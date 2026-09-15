<?php

declare(strict_types=1);

namespace LedgerDirect\Cache;

use LedgerDirect\Install\Installer;
use Psr\SimpleCache\CacheInterface;

/**
 * PSR-16 cache backed by a table of this module's own.
 *
 * **The method signatures are deliberately untyped in their parameters.**
 * Two versions of PSR-16 are in play: PrestaShop 9 ships psr/simple-cache
 * 1.0.1, whose methods are untyped, while the core requires ^3.0, whose
 * methods are fully typed. Which one `Psr\SimpleCache\CacheInterface`
 * resolves to depends on autoloader registration order — the module's wins in
 * a normal request, PrestaShop's wins if anything touches the interface before
 * the module boots. A class typed for 3.0 is a fatal error under 1.0.1 and the
 * other way round.
 *
 * Omitting the parameter types satisfies both: PHP allows a child to widen a
 * parameter type, and declaring a return type where the parent declares none
 * is equally legal. Do not "tidy" the types back in.
 *
 * PrestaShop ships a Cache class, but it is off by default
 * (`ps_cache_enable => false`) and configured for Memcache, so it cannot be
 * relied on. The store also has to survive between requests: the whole point
 * of caching an exchange rate is that the next page render, and the next
 * customer, reuse it — an in-process array would help nobody.
 *
 * The table stays tiny. Two key families live in it: the core's exchange
 * rates, one row per (network, asset, quote currency), and the sync
 * throttle's timestamps (see SyncThrottle), one row per (network, receiving
 * account). Both are disposable — every row can be refetched or simply
 * expires.
 */
final class DbRateCache implements CacheInterface
{
    /** PSR-16 reserves these characters in keys. */
    private const RESERVED_KEY_CHARACTERS = '{}()/\\@:';

    public function get($key, $default = null): mixed
    {
        $key = self::assertValidKey($key);

        $row = \Db::getInstance()->getRow(
            'SELECT `value`, `expires_at` FROM `' . self::table() . '`
             WHERE `cache_key` = "' . self::esc($key) . '"',
            false
        );

        if (!is_array($row) || $row === []) {
            return $default;
        }

        if ($row['expires_at'] !== null && (int) $row['expires_at'] <= time()) {
            // An expired entry is a miss. Dropping it here keeps the table
            // from collecting rows nobody will ever read again.
            $this->delete($key);

            return $default;
        }

        try {
            return json_decode((string) $row['value'], true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            // Unreadable payload: treat as a miss rather than propagating.
            // A cache is never allowed to be the reason a checkout fails.
            $this->delete($key);

            return $default;
        }
    }

    public function set($key, $value, $ttl = null): bool
    {
        $key = self::assertValidKey($key);

        $expiresAt = self::expiryFor($ttl);

        // PSR-16: a non-positive TTL means the entry is already expired.
        if ($expiresAt !== null && $expiresAt <= time()) {
            return $this->delete($key);
        }

        try {
            // JSON_PRESERVE_ZERO_FRACTION keeps a rate of exactly 3.0 from
            // being written as "3" and read back as an int. The core tolerates
            // that shape, but there is no reason to hand it one.
            $encoded = json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        } catch (\JsonException) {
            return false;
        }

        return (bool) \Db::getInstance()->execute(
            'INSERT INTO `' . self::table() . '` (`cache_key`, `value`, `expires_at`)
             VALUES ("' . self::esc($key) . '", "' . self::esc($encoded) . '", '
                . ($expiresAt === null ? 'NULL' : $expiresAt) . ')
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `expires_at` = VALUES(`expires_at`)'
        );
    }

    public function delete($key): bool
    {
        $key = self::assertValidKey($key);

        return (bool) \Db::getInstance()->execute(
            'DELETE FROM `' . self::table() . '` WHERE `cache_key` = "' . self::esc($key) . '"'
        );
    }

    public function clear(): bool
    {
        return (bool) \Db::getInstance()->execute('TRUNCATE TABLE `' . self::table() . '`');
    }

    /**
     * @param iterable<string> $keys
     *
     * @return iterable<string, mixed>
     */
    public function getMultiple($keys, $default = null): iterable
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }

        return $result;
    }

    /**
     * @param iterable<string, mixed> $values
     */
    public function setMultiple($values, $ttl = null): bool
    {
        $ok = true;
        foreach ($values as $key => $value) {
            $ok = $this->set((string) $key, $value, $ttl) && $ok;
        }

        return $ok;
    }

    /**
     * @param iterable<string> $keys
     */
    public function deleteMultiple($keys): bool
    {
        $ok = true;
        foreach ($keys as $key) {
            $ok = $this->delete($key) && $ok;
        }

        return $ok;
    }

    public function has($key): bool
    {
        $missing = new \stdClass();

        return $this->get($key, $missing) !== $missing;
    }

    /**
     * Unix timestamp at which the entry expires, or null for no expiry.
     */
    private static function expiryFor($ttl): ?int
    {
        if ($ttl === null) {
            return null;
        }

        if (is_int($ttl)) {
            return time() + $ttl;
        }

        if ($ttl instanceof \DateInterval) {
            return (new \DateTimeImmutable())->add($ttl)->getTimestamp();
        }

        throw new InvalidCacheKeyException('A TTL must be null, an integer or a DateInterval.');
    }

    /**
     * @param mixed $key
     *
     * @return string the key, normalised to a string
     */
    private static function assertValidKey($key): string
    {
        if (!is_string($key)) {
            throw new InvalidCacheKeyException('A cache key must be a string.');
        }

        if ($key === '') {
            throw new InvalidCacheKeyException('A cache key must not be empty.');
        }

        if (strpbrk($key, self::RESERVED_KEY_CHARACTERS) !== false) {
            throw new InvalidCacheKeyException(sprintf('The cache key "%s" contains one of the characters PSR-16 reserves (%s).', $key, self::RESERVED_KEY_CHARACTERS));
        }

        if (strlen($key) > 191) {
            throw new InvalidCacheKeyException('A cache key must not exceed 191 characters.');
        }

        return $key;
    }

    private static function table(): string
    {
        return _DB_PREFIX_ . Installer::TABLE_RATE_CACHE;
    }

    /** SQL escaping only — see PrestaShopXrplTransactionRepository::esc(). */
    private static function esc(string $value): string
    {
        return \Db::getInstance()->escape($value, true, false);
    }
}
