<?php

declare(strict_types=1);

namespace LedgerDirect\Service;

use Hardcastle\LedgerDirect\Core\Payment\PaymentStatus;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Keeps the payment page from turning into an amplifier against the
 * merchant's XRPL node.
 *
 * Every ledger sync costs a node request (measured at ~1 s against ~0.09 s
 * without), the poll endpoint is reachable without a login, and every waiting
 * customer polls it every few seconds. The core's payment-status contract
 * therefore makes throttling mandatory and names the interval
 * (PaymentStatus::MIN_SYNC_INTERVAL_SECONDS); where the timestamp lives and
 * what the unit of throttling is, it leaves to the adapter.
 *
 * The unit here is the receiving account per network, not the order. The sync
 * fetches the whole account's transactions in one go and matching afterwards
 * is local, so throttling per order would still let ten waiting customers
 * trigger ten identical syncs inside one window. Per account it is exactly
 * one — which satisfies the contract's per-order wording trivially.
 *
 * The timestamp sits in the module's PSR-16 store with the interval as its
 * TTL: "an entry exists" means "synced recently". A broken store degrades
 * towards syncing, never towards silently skipping.
 */
final class SyncThrottle
{
    /**
     * Versioned like the rate cache's keys, so a change in what the entry
     * means can never be misread by a leftover row. Dots are fine, the
     * characters PSR-16 reserves are not — an r-address contains neither.
     */
    private const KEY_PREFIX = 'ledger-direct.sync.v1';

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly int $intervalSeconds = PaymentStatus::MIN_SYNC_INTERVAL_SECONDS,
    ) {
    }

    /**
     * Whether the last sync for this account on this network is older than
     * the interval — or unknown, which counts as old.
     */
    public function shouldSync(string $network, string $account): bool
    {
        try {
            return $this->cache->get(self::key($network, $account)) === null;
        } catch (\Throwable $exception) {
            $this->logger->warning('LedgerDirect: sync throttle store unreadable, syncing anyway', [
                'exception' => $exception->getMessage(),
            ]);

            return true;
        }
    }

    public function markSynced(string $network, string $account): void
    {
        try {
            $this->cache->set(self::key($network, $account), time(), $this->intervalSeconds);
        } catch (\Throwable $exception) {
            // Never fatal: the worst case is one more sync than necessary.
            $this->logger->warning('LedgerDirect: could not record the sync timestamp', [
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * The network is part of the key for the same reason it is part of the
     * rate cache's: a mainnet and a testnet sync of the same address are two
     * different things.
     */
    private static function key(string $network, string $account): string
    {
        return self::KEY_PREFIX . '.' . $network . '.' . $account;
    }
}
