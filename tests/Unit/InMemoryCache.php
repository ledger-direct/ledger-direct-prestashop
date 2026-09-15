<?php

declare(strict_types=1);

namespace LedgerDirect\Tests\Unit;

use Psr\SimpleCache\CacheInterface;

/**
 * Array-backed PSR-16 store with an injectable clock, so a throttle test can
 * step through time instead of sleeping.
 *
 * Parameters are untyped for the same reason DbRateCache's are: two versions
 * of psr/simple-cache exist in this project (see the class comment there),
 * and a fake typed for one of them is a fatal error under the other.
 */
final class InMemoryCache implements CacheInterface
{
    /** @var array<string, array{value: mixed, expires_at: int|null}> */
    private array $entries = [];

    public int $now = 1_700_000_000;

    /** Set to make every call throw, as a store on a broken connection would. */
    public ?\Throwable $failure = null;

    public function get($key, $default = null): mixed
    {
        $this->failIfBroken();

        if (!isset($this->entries[$key])) {
            return $default;
        }

        $expiresAt = $this->entries[$key]['expires_at'];
        if ($expiresAt !== null && $expiresAt <= $this->now) {
            unset($this->entries[$key]);

            return $default;
        }

        return $this->entries[$key]['value'];
    }

    public function set($key, $value, $ttl = null): bool
    {
        $this->failIfBroken();

        $seconds = $ttl instanceof \DateInterval
            ? (int) $ttl->format('%s')
            : $ttl;

        $this->entries[$key] = [
            'value' => $value,
            'expires_at' => $seconds === null ? null : $this->now + (int) $seconds,
        ];

        return true;
    }

    public function delete($key): bool
    {
        unset($this->entries[$key]);

        return true;
    }

    public function clear(): bool
    {
        $this->entries = [];

        return true;
    }

    public function getMultiple($keys, $default = null): iterable
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }

        return $result;
    }

    public function setMultiple($values, $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value, $ttl);
        }

        return true;
    }

    public function deleteMultiple($keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }

        return true;
    }

    public function has($key): bool
    {
        return $this->get($key) !== null;
    }

    /** @return string[] */
    public function keys(): array
    {
        return array_keys($this->entries);
    }

    private function failIfBroken(): void
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
    }
}
