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

namespace LedgerDirect\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Hardcastle\LedgerDirect\Core\ChainCatalog;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntentService;
use Hardcastle\LedgerDirect\Core\Payment\SettlementPolicy;
use Hardcastle\LedgerDirect\Core\Price\PriceService;
use Hardcastle\LedgerDirect\Core\Xrpl\DestinationTagService;
use Hardcastle\LedgerDirect\Core\Xrpl\SyncService;
use Hardcastle\LedgerDirect\Core\Xrpl\SyncThrottle;
use Hardcastle\LedgerDirect\Core\Xrpl\XrplClient;
use LedgerDirect\Cache\DbRateCache;
use LedgerDirect\Log\PrestaShopLoggerAdapter;
use LedgerDirect\Port\PrestaShopConfigProvider;
use LedgerDirect\Port\PrestaShopXrplTransactionRepository;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface as SimpleCacheInterface;

/**
 * Wires the core's services to this platform's port implementations.
 *
 * PrestaShop's own service container isn't used here on purpose: front
 * controllers and the legacy module class are reachable from contexts where
 * the container isn't reliably built, and the object graph is small enough
 * that hand-wiring stays clearer than a set of YAML definitions. Instances are
 * memoised per request, so repeated getters don't rebuild the graph.
 */
final class ServiceFactory
{
    private static ?self $instance = null;

    private ?ClientInterface $httpClient = null;
    private ?HttpFactory $httpFactory = null;
    private ?LoggerInterface $logger = null;
    private ?PrestaShopConfigProvider $configProvider = null;
    /** @var array<string, PrestaShopXrplTransactionRepository> by chain */
    private array $transactionRepositories = [];
    private ?SimpleCacheInterface $rateCache = null;
    private ?PriceService $priceService = null;
    /** @var array<string, PaymentIntentService> by chain */
    private array $paymentIntentServices = [];
    /** @var array<string, SyncService> by chain */
    private array $syncServices = [];
    private ?SettlementPolicy $settlementPolicy = null;
    /** @var array<string, SyncThrottle> by chain */
    private array $syncThrottles = [];

    private function __construct()
    {
    }

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    public function getConfigProvider(): PrestaShopConfigProvider
    {
        return $this->configProvider ??= new PrestaShopConfigProvider();
    }

    /**
     * The core's services come per chain. Xahau speaks the XRP Ledger's protocol on a
     * network of its own, so the same classes serve it, built for the chain: another node,
     * another transaction table and tag counter, another throttle mark. Without a chain the
     * XRP Ledger's set is meant, as before.
     */
    public function getTransactionRepository(string $chain = ChainCatalog::XRPL): PrestaShopXrplTransactionRepository
    {
        return $this->transactionRepositories[$chain] ??= new PrestaShopXrplTransactionRepository($chain);
    }

    public function getLogger(): LoggerInterface
    {
        return $this->logger ??= new PrestaShopLoggerAdapter();
    }

    /**
     * Guzzle 7 implements PSR-18 directly, so no bridge package is needed.
     *
     * `verify => true` is set explicitly rather than left to the default:
     * INVARIANTS.md makes SSL verification an adapter responsibility, and an
     * explicit true is what makes a later "just disable it to debug" edit show
     * up in review instead of hiding in an omission.
     */
    public function getHttpClient(): ClientInterface
    {
        return $this->httpClient ??= new Client([
            'verify' => true,
            'timeout' => 15,
            'connect_timeout' => 5,
        ]);
    }

    /**
     * The rate cache is injected, so the core skips the oracle set while a
     * quote is fresh — measured at 314 ms for XRP/EUR, on the critical path of
     * every checkout render. It also lets the core fall back to a recent rate
     * when no oracle answers, instead of the payment method vanishing from a
     * checkout the customer is standing in.
     *
     * The freshness window stays at the core's default (60 s).
     */
    public function getPriceService(): PriceService
    {
        return $this->priceService ??= new PriceService(
            $this->getHttpClient(),
            $this->getHttpFactory(),
            $this->getLogger(),
            $this->getRateCache()
        );
    }

    public function getRateCache(): SimpleCacheInterface
    {
        return $this->rateCache ??= new DbRateCache();
    }

    public function getPaymentIntentService(string $chain = ChainCatalog::XRPL): PaymentIntentService
    {
        return $this->paymentIntentServices[$chain] ??= new PaymentIntentService(
            $this->getPriceService(),
            new DestinationTagService($this->getTransactionRepository($chain)),
            $this->getConfigProvider(),
            chain: $chain
        );
    }

    /**
     * The decision whether a delivered amount pays for a quote — the core's, so
     * every LedgerDirect plugin calls an order paid under the same conditions
     * (INVARIANTS.md, "Settlement").
     */
    public function getSettlementPolicy(): SettlementPolicy
    {
        return $this->settlementPolicy ??= new SettlementPolicy();
    }

    /**
     * The rate limit on ledger syncs triggered from the payment page — the
     * core's since 0.7, keyed per network and receiving account. Shares the
     * rate cache's store: the same "set within the last N seconds" semantics,
     * no schema of its own, and a table that is disposable anyway.
     */
    public function getSyncThrottle(string $chain = ChainCatalog::XRPL): SyncThrottle
    {
        return $this->syncThrottles[$chain] ??= new SyncThrottle($this->getRateCache(), $this->getLogger(), chain: $chain);
    }

    /**
     * The rate cache's store also keeps the sync's note on a testnet reset (core 0.11.1): after
     * the reset only the first sync per account sends the stale ledger cursor and fails, later
     * ones continue from where the resync got to. The chain is part of the note's key.
     */
    public function getSyncService(string $chain = ChainCatalog::XRPL): SyncService
    {
        return $this->syncServices[$chain] ??= new SyncService(
            $this->getXrplClient($chain),
            $this->getTransactionRepository($chain),
            $this->getLogger(),
            $this->getRateCache(),
            $chain
        );
    }

    /** The node client of a chain: the XRP Ledger's or Xahau's nodes, fixed in the core. */
    public function getXrplClient(string $chain = ChainCatalog::XRPL): XrplClient
    {
        return new XrplClient($this->getHttpClient(), $this->getHttpFactory(), $this->getHttpFactory(), $chain);
    }

    /** Guzzle's HttpFactory is both the PSR-17 request and stream factory. */
    private function getHttpFactory(): HttpFactory
    {
        return $this->httpFactory ??= new HttpFactory();
    }
}
