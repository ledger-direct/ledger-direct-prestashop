<?php

declare(strict_types=1);

namespace LedgerDirect\Tests\Unit;

use LedgerDirect\Service\SyncThrottle;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The rate limit on ledger syncs from the payment page. Time is stepped
 * through the fake store's clock, never slept.
 */
final class SyncThrottleTest extends TestCase
{
    private const ACCOUNT = 'raXkRCAYkqaoFYCeVej93SzCTtiAbbRzAg';

    private InMemoryCache $cache;
    private SyncThrottle $throttle;

    protected function setUp(): void
    {
        $this->cache = new InMemoryCache();
        $this->throttle = new SyncThrottle($this->cache, new NullLogger(), 5);
    }

    public function testTheFirstCallSyncs(): void
    {
        self::assertTrue($this->throttle->shouldSync('testnet', self::ACCOUNT));
    }

    public function testASecondCallWithinTheIntervalDoesNot(): void
    {
        $this->throttle->markSynced('testnet', self::ACCOUNT);
        $this->cache->now += 4;

        self::assertFalse($this->throttle->shouldSync('testnet', self::ACCOUNT));
    }

    public function testOnceTheIntervalHasPassedItSyncsAgain(): void
    {
        $this->throttle->markSynced('testnet', self::ACCOUNT);
        $this->cache->now += 5;

        self::assertTrue($this->throttle->shouldSync('testnet', self::ACCOUNT));
    }

    /**
     * The unit is the receiving account per network: a mainnet sync says
     * nothing about the testnet, and another account is another account.
     */
    public function testNetworksAndAccountsDoNotShareAWindow(): void
    {
        $this->throttle->markSynced('testnet', self::ACCOUNT);

        self::assertTrue($this->throttle->shouldSync('mainnet', self::ACCOUNT));
        self::assertTrue($this->throttle->shouldSync('testnet', 'rAnotherAccount'));
        self::assertFalse($this->throttle->shouldSync('testnet', self::ACCOUNT));
    }

    /**
     * A broken store must degrade towards syncing — one request too many is
     * a nuisance, an order that is never checked is a lost sale.
     */
    public function testABrokenStoreNeverBlocksTheSync(): void
    {
        $this->throttle->markSynced('testnet', self::ACCOUNT);
        $this->cache->failure = new \RuntimeException('connection lost');

        self::assertTrue($this->throttle->shouldSync('testnet', self::ACCOUNT));

        // And marking must not throw either.
        $this->throttle->markSynced('testnet', self::ACCOUNT);
    }

    /**
     * The interval is the TTL: the store forgets the entry by itself, so the
     * table never accumulates rows.
     */
    public function testTheEntryExpiresWithTheInterval(): void
    {
        $this->throttle->markSynced('testnet', self::ACCOUNT);
        self::assertCount(1, $this->cache->keys());

        $this->cache->now += 5;
        $this->throttle->shouldSync('testnet', self::ACCOUNT);

        self::assertSame([], $this->cache->keys());
    }

    /**
     * PSR-16 reserves {}()/\@: in keys, and the store enforces that. An
     * r-address and a network name contain none of them; the key must not add
     * any either.
     */
    public function testTheKeyAvoidsTheCharactersPsr16Reserves(): void
    {
        $this->throttle->markSynced('testnet', self::ACCOUNT);

        foreach ($this->cache->keys() as $key) {
            self::assertFalse((bool) strpbrk($key, '{}()/\\@:'), $key);
            self::assertStringContainsString('testnet', $key);
            self::assertStringContainsString(self::ACCOUNT, $key);
        }
    }
}
