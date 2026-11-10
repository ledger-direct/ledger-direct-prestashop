<?php

/**
 * LedgerDirect for PrestaShop
 *
 * Copyright (c) 2026 Hardcastle
 * This source file is subject to the MIT license that is bundled with this module in LICENSE.md
 * and available at https://opensource.org/licenses/MIT.
 *
 * @author    Hardcastle <https://www.ledger-direct.com>
 * @copyright 2026 Hardcastle
 * @license   https://opensource.org/licenses/MIT MIT License
 */

declare(strict_types=1);

namespace LedgerDirect\Tests\Integration;

use GuzzleHttp\Psr7\Response;
use Hardcastle\LedgerDirect\Core\ChainCatalog;
use Hardcastle\LedgerDirect\Core\Xrpl\XrplTransaction;
use LedgerDirect\Install\Installer;
use LedgerDirect\Service\ServiceFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The sync as the module builds it, after a testnet reset.
 *
 * Offline: the node is a stub that answers lgrIdxsInvalid to any request with a ledger
 * cursor and an empty history to one without. A stored row from before the reset sits far
 * above the live ledger, so its cursor is unservable. With the module's cache handed to the
 * core, only the first sync sends it; without, every sync does. The note is kept per chain:
 * the same address is a different account on the XRP Ledger and on Xahau.
 */
final class SyncServiceWiringTest extends IntegrationTestCase
{
    private const NETWORK = 'testnet';
    private const STALE_LEDGER_INDEX = '99999999';

    private string $account;
    /** @var list<array<string, mixed>> account_tx params the stub received */
    private array $requests = [];
    /** @var array<string, mixed> the factory's properties before the stub went in */
    private array $saved = [];

    protected function setUp(): void
    {
        $this->account = 'rSyncWiring' . bin2hex(random_bytes(8));

        $requests = &$this->requests;
        $this->replaceInFactory('httpClient', new class($requests) implements ClientInterface {
            /** @param list<array<string, mixed>> $requests */
            public function __construct(private array &$requests)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $params = json_decode((string) $request->getBody(), true)['params'][0];
                $this->requests[] = $params;

                $result = isset($params['ledger_index_min'])
                    ? ['status' => 'error', 'error' => 'lgrIdxsInvalid']
                    : ['status' => 'success', 'transactions' => []];

                return new Response(200, ['Content-Type' => 'application/json'], json_encode(['result' => $result]));
            }
        });
        $this->replaceInFactory('syncServices', []);
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $property => $value) {
            (new \ReflectionProperty(ServiceFactory::class, $property))->setValue(ServiceFactory::getInstance(), $value);
        }

        foreach ([Installer::TABLE_TX, Installer::TABLE_XAHAU_TX] as $table) {
            \Db::getInstance()->execute(
                'DELETE FROM `' . _DB_PREFIX_ . $table . '`
                 WHERE `destination` = "' . pSQL($this->account) . '"'
            );
        }
        \Db::getInstance()->execute(
            'DELETE FROM `' . _DB_PREFIX_ . Installer::TABLE_RATE_CACHE . '`
             WHERE `cache_key` LIKE "ledger_direct.cursor.v1.%.' . pSQL($this->accountDigest()) . '"'
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function chains(): array
    {
        return ['XRP Ledger' => [ChainCatalog::XRPL], 'Xahau' => [ChainCatalog::XAHAU]];
    }

    #[DataProvider('chains')]
    public function testAfterATestnetResetOnlyTheFirstSyncSendsTheStaleCursor(string $chain): void
    {
        ServiceFactory::getInstance()->getTransactionRepository($chain)->saveTransactions([
            new XrplTransaction(
                network: self::NETWORK,
                ledgerIndex: self::STALE_LEDGER_INDEX,
                hash: strtoupper(bin2hex(random_bytes(32))),
                ctid: 'C' . strtoupper(bin2hex(random_bytes(7))) . '1',
                account: 'rSenderTest',
                destination: $this->account,
                destinationTag: 1,
                date: 800000000,
                meta: ['delivered_amount' => '1000000'],
                tx: ['TransactionType' => 'Payment'],
            ),
        ]);

        $sync = ServiceFactory::getInstance()->getSyncService($chain);

        $sync->syncTransactions($this->account, self::NETWORK);
        $sync->syncTransactions($this->account, self::NETWORK);
        $sync->syncTransactions($this->account, self::NETWORK);

        $withCursor = array_filter($this->requests, static fn (array $params): bool => isset($params['ledger_index_min']));

        self::assertCount(1, $withCursor, 'Every sync sent the stale cursor: the sync service has no cache.');
        self::assertSame((int) self::STALE_LEDGER_INDEX + 1, array_values($withCursor)[0]['ledger_index_min']);

        $cache = ServiceFactory::getInstance()->getRateCache();
        foreach (self::chains() as [$keyChain]) {
            $note = $cache->get($this->cursorKey($keyChain));
            if ($keyChain === $chain) {
                self::assertIsArray($note, "The reset note is not under {$chain}'s key.");
            } else {
                self::assertNull($note, "A sync on {$chain} left its reset note under {$keyChain}'s key.");
            }
        }
    }

    /** The core's key for the note: chain, network and the account's digest. */
    private function cursorKey(string $chain): string
    {
        return 'ledger_direct.cursor.v1.' . strtolower($chain) . '.' . self::NETWORK . '.' . $this->accountDigest();
    }

    private function accountDigest(): string
    {
        return substr(hash('sha256', $this->account), 0, 16);
    }

    private function replaceInFactory(string $property, mixed $value): void
    {
        $reflection = new \ReflectionProperty(ServiceFactory::class, $property);
        $this->saved[$property] = $reflection->getValue(ServiceFactory::getInstance());
        $reflection->setValue(ServiceFactory::getInstance(), $value);
    }
}
