<?php

declare(strict_types=1);

namespace LedgerDirect\Tests\Integration;

use LedgerDirect\Cache\DbRateCache;
use LedgerDirect\Install\Installer;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\SimpleCache\InvalidArgumentException;

/**
 * The PSR-16 store behind the core's exchange-rate cache.
 *
 * The caching *policy* — freshness window, stale-while-error, what happens
 * when no oracle answers — belongs to the core and is tested there. What is
 * this adapter's to get right is the storage contract underneath it.
 */
final class DbRateCacheTest extends IntegrationTestCase
{
    private DbRateCache $cache;
    private string $key;

    protected function setUp(): void
    {
        $this->cache = new DbRateCache();
        $this->key = 'ledger-direct.test.' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        \Db::getInstance()->execute(
            'DELETE FROM `' . _DB_PREFIX_ . Installer::TABLE_RATE_CACHE . '`
             WHERE `cache_key` LIKE "ledger-direct.test.%"'
        );
    }

    public function testStoresAndReturnsAnEntry(): void
    {
        $entry = ['rate' => 1.2842, 'fetched_at' => time()];

        self::assertTrue($this->cache->set($this->key, $entry, 300));
        self::assertSame($entry, $this->cache->get($this->key));
        self::assertTrue($this->cache->has($this->key));
    }

    public function testAMissReturnsTheDefault(): void
    {
        self::assertNull($this->cache->get($this->key));
        self::assertSame('fallback', $this->cache->get($this->key, 'fallback'));
        self::assertFalse($this->cache->has($this->key));
    }

    /**
     * The core writes with the stale horizon as TTL and keeps freshness inside
     * the value, so this expiry is the point past which even a stale read must
     * fail. It has to be honoured exactly.
     */
    public function testAnExpiredEntryIsAMissAndItsRowIsDropped(): void
    {
        $this->cache->set($this->key, ['rate' => 1.0, 'fetched_at' => time()], 300);
        $this->expireStoredEntry();

        self::assertNull($this->cache->get($this->key));
        self::assertSame(0, $this->countRows(), 'An expired row should not be left behind.');
    }

    public function testATtlOfZeroOrLessStoresNothing(): void
    {
        $this->cache->set($this->key, ['rate' => 1.0, 'fetched_at' => time()], 0);
        self::assertNull($this->cache->get($this->key));

        $this->cache->set($this->key, ['rate' => 1.0, 'fetched_at' => time()], -5);
        self::assertNull($this->cache->get($this->key));
    }

    public function testATtlGivenAsAnIntervalIsHonoured(): void
    {
        $this->cache->set($this->key, ['rate' => 1.0, 'fetched_at' => time()], new \DateInterval('PT5M'));

        $expiresIn = $this->storedExpiry() - time();
        self::assertGreaterThan(280, $expiresIn);
        self::assertLessThanOrEqual(300, $expiresIn);
    }

    public function testAnEntryWithoutTtlDoesNotExpire(): void
    {
        $this->cache->set($this->key, ['rate' => 1.0, 'fetched_at' => time()]);

        self::assertNull($this->storedExpiryRaw());
        self::assertNotNull($this->cache->get($this->key));
    }

    /**
     * A rate of exactly 3.0 must not come back as int 3.
     *
     * The core tolerates the integer shape on purpose, because some backends
     * cannot avoid it — but this one can, and a whole-number rate is not an
     * exotic case: it is what a USD-pegged stablecoin produces every time.
     */
    public function testAWholeNumberRateKeepsItsFraction(): void
    {
        $this->cache->set($this->key, ['rate' => 2.0, 'fetched_at' => time()], 300);

        self::assertStringContainsString('2.0', $this->storedValue());
        self::assertSame(2.0, $this->cache->get($this->key)['rate']);
    }

    public function testUnreadableStoredDataIsTreatedAsAMiss(): void
    {
        $this->cache->set($this->key, ['rate' => 1.0, 'fetched_at' => time()], 300);
        \Db::getInstance()->execute(
            'UPDATE `' . _DB_PREFIX_ . Installer::TABLE_RATE_CACHE . '`
             SET `value` = "not json at all" WHERE `cache_key` = "' . pSQL($this->key) . '"'
        );

        self::assertNull($this->cache->get($this->key));
        self::assertSame(0, $this->countRows());
    }

    public function testDeleteAndClearRemoveEntries(): void
    {
        $this->cache->set($this->key, ['rate' => 1.0, 'fetched_at' => time()], 300);
        self::assertTrue($this->cache->delete($this->key));
        self::assertNull($this->cache->get($this->key));
    }

    public function testMultipleOperations(): void
    {
        $a = $this->key . '.a';
        $b = $this->key . '.b';

        $this->cache->setMultiple([$a => ['rate' => 1.0, 'fetched_at' => 1], $b => ['rate' => 2.0, 'fetched_at' => 2]], 300);

        $read = $this->cache->getMultiple([$a, $b, $this->key . '.missing'], 'none');
        self::assertSame(['rate' => 1.0, 'fetched_at' => 1], $read[$a]);
        self::assertSame('none', $read[$this->key . '.missing']);

        self::assertTrue($this->cache->deleteMultiple([$a, $b]));
        self::assertFalse($this->cache->has($a));
    }

    #[DataProvider('illegalKeys')]
    public function testIllegalKeysAreRejected(string $key): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->cache->get($key);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function illegalKeys(): array
    {
        return [
            'empty' => [''],
            // The characters PSR-16 reserves for future use.
            'braces' => ['rate{eur}'],
            'parentheses' => ['rate(eur)'],
            'slash' => ['rate/eur'],
            'backslash' => ['rate\\eur'],
            'at sign' => ['rate@eur'],
            'colon' => ['rate:eur'],
            'too long' => [str_repeat('k', 192)],
        ];
    }

    private function countRows(): int
    {
        return (int) \Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . Installer::TABLE_RATE_CACHE . '`
             WHERE `cache_key` = "' . pSQL($this->key) . '"',
            false
        );
    }

    private function storedValue(): string
    {
        return (string) \Db::getInstance()->getValue(
            'SELECT `value` FROM `' . _DB_PREFIX_ . Installer::TABLE_RATE_CACHE . '`
             WHERE `cache_key` = "' . pSQL($this->key) . '"',
            false
        );
    }

    private function storedExpiry(): int
    {
        return (int) $this->storedExpiryRaw();
    }

    private function storedExpiryRaw(): ?string
    {
        $value = \Db::getInstance()->getValue(
            'SELECT `expires_at` FROM `' . _DB_PREFIX_ . Installer::TABLE_RATE_CACHE . '`
             WHERE `cache_key` = "' . pSQL($this->key) . '"',
            false
        );

        return ($value === false || $value === null || $value === '') ? null : (string) $value;
    }

    private function expireStoredEntry(): void
    {
        \Db::getInstance()->execute(
            'UPDATE `' . _DB_PREFIX_ . Installer::TABLE_RATE_CACHE . '`
             SET `expires_at` = UNIX_TIMESTAMP() - 1 WHERE `cache_key` = "' . pSQL($this->key) . '"'
        );
    }
}
