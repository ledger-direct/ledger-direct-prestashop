<?php

declare(strict_types=1);

namespace LedgerDirect\Port;

use Db;
use Hardcastle\LedgerDirect\Core\Port\XrplTransactionRepositoryInterface;
use Hardcastle\LedgerDirect\Core\Xrpl\XrplTransaction;
use LedgerDirect\Install\Installer;

/**
 * Persistence for the two XRPL tables, on PrestaShop's `Db` layer.
 *
 * The core knows only the logical table names and builds no SQL at all, so
 * every physical name is assembled here as `_DB_PREFIX_` + base name — in one
 * place, via self::table(), so the rule can't drift between queries.
 */
final class PrestaShopXrplTransactionRepository implements XrplTransactionRepositoryInterface
{
    /**
     * Issues the next sequence number for $destinationAccount: 0 on the first
     * call for that account, 1 on the second, and so on.
     *
     * Atomicity matters here — two customers checking out at the same moment
     * must never receive the same destination tag, or one order gets matched
     * against the other's payment. A SELECT-then-UPDATE pair has exactly that
     * race, so the counter is bumped in a *single* statement and the new value
     * is read back through LAST_INSERT_ID(), which is connection-local and
     * therefore unaffected by whatever another request did in between.
     *
     * The stored column counts tags *issued* (1, 2, 3 …) so that the same
     * statement works for the first insert and every later bump; the port's
     * contract is a 0-based sequence, hence the -1.
     */
    public function nextDestinationTagSequence(string $destinationAccount): int
    {
        $db = \Db::getInstance();

        $sql = 'INSERT INTO `' . self::table(Installer::TABLE_DESTINATION_TAG) . '`
                    (`destination_account`, `sequence`)
                VALUES ("' . self::esc($destinationAccount) . '", LAST_INSERT_ID(1))
                ON DUPLICATE KEY UPDATE `sequence` = LAST_INSERT_ID(`sequence` + 1)';

        if (!$db->execute($sql)) {
            throw new \RuntimeException('LedgerDirect: could not issue a destination-tag sequence for ' . $destinationAccount . '.');
        }

        return ((int) $db->Insert_ID()) - 1;
    }

    /**
     * @param string[] $hashes
     *
     * @return string[]
     */
    public function findExistingHashes(array $hashes): array
    {
        $escaped = [];
        foreach ($hashes as $hash) {
            $hash = (string) $hash;
            if ($hash !== '') {
                $escaped[] = '"' . self::esc($hash) . '"';
            }
        }

        if ($escaped === []) {
            return [];
        }

        $rows = \Db::getInstance()->executeS(
            'SELECT `hash` FROM `' . self::table(Installer::TABLE_TX) . '`
             WHERE `hash` IN (' . implode(',', $escaped) . ')'
        );

        if (!is_array($rows)) {
            return [];
        }

        return array_map(static fn (array $row): string => (string) $row['hash'], $rows);
    }

    /**
     * @param XrplTransaction[] $transactions
     */
    public function saveTransactions(array $transactions): void
    {
        if ($transactions === []) {
            return;
        }

        $values = [];
        foreach ($transactions as $transaction) {
            $values[] = sprintf(
                '(%s, "%s", "%s", "%s", "%s", %s, %d, "%s", "%s")',
                (string) (int) $transaction->ledgerIndex,
                self::esc($transaction->hash),
                self::esc($transaction->ctid),
                self::esc($transaction->account),
                self::esc($transaction->destination),
                $transaction->destinationTag === null ? 'NULL' : (string) $transaction->destinationTag,
                $transaction->date,
                self::esc(self::encodeJson($transaction->meta)),
                self::esc(self::encodeJson($transaction->tx))
            );
        }

        // INSERT IGNORE, not a prior existence check: SyncService already
        // de-duplicates against findExistingHashes(), but two overlapping syncs
        // can still race between that check and this write. The unique index on
        // `hash` is what actually guarantees uniqueness; IGNORE just lets the
        // loser of the race proceed quietly instead of erroring.
        $sql = 'INSERT IGNORE INTO `' . self::table(Installer::TABLE_TX) . '`
                    (`ledger_index`, `hash`, `ctid`, `account`, `destination`, `destination_tag`, `date`, `meta`, `tx`)
                VALUES ' . implode(',', $values);

        if (!\Db::getInstance()->execute($sql)) {
            throw new \RuntimeException('LedgerDirect: could not store synced XRPL transactions.');
        }
    }

    public function findTransaction(string $destination, int $destinationTag): ?XrplTransaction
    {
        $rows = \Db::getInstance()->executeS(
            'SELECT * FROM `' . self::table(Installer::TABLE_TX) . '`
             WHERE `destination` = "' . self::esc($destination) . '"
               AND `destination_tag` = ' . $destinationTag . '
             ORDER BY `ledger_index` ASC
             LIMIT 1'
        );

        if (!is_array($rows) || $rows === []) {
            return null;
        }

        return self::hydrate($rows[0]);
    }

    public function getLastSyncedLedgerIndex(): ?string
    {
        // MAX() over a BIGINT UNSIGNED column, so this is a numeric maximum.
        // The column is deliberately not a string type: ledger indices compared
        // lexicographically would order "9" above "10" and the sync would then
        // re-fetch from the wrong point forever.
        $value = \Db::getInstance()->getValue(
            'SELECT MAX(`ledger_index`) FROM `' . self::table(Installer::TABLE_TX) . '`',
            false
        );

        return ($value === false || $value === null || $value === '') ? null : (string) $value;
    }

    /**
     * Clears the synced-transaction cache only. The destination-tag counter is
     * deliberately left alone: it is not re-derivable from the ledger, and
     * resetting it would re-issue tags that earlier orders already used.
     */
    public function truncate(): void
    {
        if (!\Db::getInstance()->execute('TRUNCATE TABLE `' . self::table(Installer::TABLE_TX) . '`')) {
            throw new \RuntimeException('LedgerDirect: could not truncate the XRPL transaction table.');
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): XrplTransaction
    {
        return new XrplTransaction(
            ledgerIndex: (string) $row['ledger_index'],
            hash: (string) $row['hash'],
            ctid: (string) $row['ctid'],
            account: (string) $row['account'],
            destination: (string) $row['destination'],
            destinationTag: $row['destination_tag'] === null ? null : (int) $row['destination_tag'],
            date: (int) $row['date'],
            meta: self::decodeJson((string) $row['meta']),
            tx: self::decodeJson((string) $row['tx']),
        );
    }

    /**
     * @param array<string, mixed> $value
     */
    private static function encodeJson(array $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('LedgerDirect: could not serialise XRPL transaction data: ' . $exception->getMessage(), 0, $exception);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeJson(string $value): array
    {
        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('LedgerDirect: stored XRPL transaction data is not valid JSON: ' . $exception->getMessage(), 0, $exception);
        }

        return is_array($decoded) ? $decoded : [];
    }

    private static function table(string $baseName): string
    {
        return _DB_PREFIX_ . $baseName;
    }

    /**
     * SQL escaping only — deliberately not pSQL().
     *
     * pSQL() defaults to running the value through strip_tags(nl2br($value))
     * and escaping backticks, which is right for user-entered text headed for
     * a template but destroys the payloads stored here: a `meta`/`tx` JSON blob
     * containing a "<" would come back with a chunk silently removed, and any
     * newline would turn into a literal "<br />". Db::escape($v, true, false)
     * skips both transforms and does the one thing needed — making the value
     * safe to sit inside a quoted SQL literal.
     */
    private static function esc(string $value): string
    {
        return \Db::getInstance()->escape($value, true, false);
    }
}
