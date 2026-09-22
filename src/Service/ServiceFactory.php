<?php

declare(strict_types=1);

namespace LedgerDirect\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
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
    private ?PrestaShopXrplTransactionRepository $transactionRepository = null;
    private ?SimpleCacheInterface $rateCache = null;
    private ?PriceService $priceService = null;
    private ?PaymentIntentService $paymentIntentService = null;
    private ?SyncService $syncService = null;
    private ?SettlementPolicy $settlementPolicy = null;
    private ?SyncThrottle $syncThrottle = null;

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

    public function getTransactionRepository(): PrestaShopXrplTransactionRepository
    {
        return $this->transactionRepository ??= new PrestaShopXrplTransactionRepository();
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

    public function getPaymentIntentService(): PaymentIntentService
    {
        return $this->paymentIntentService ??= new PaymentIntentService(
            $this->getPriceService(),
            new DestinationTagService($this->getTransactionRepository()),
            $this->getConfigProvider()
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
    public function getSyncThrottle(): SyncThrottle
    {
        return $this->syncThrottle ??= new SyncThrottle($this->getRateCache(), $this->getLogger());
    }

    public function getSyncService(): SyncService
    {
        return $this->syncService ??= new SyncService(
            new XrplClient($this->getHttpClient(), $this->getHttpFactory(), $this->getHttpFactory()),
            $this->getTransactionRepository(),
            $this->getLogger()
        );
    }

    /** Guzzle's HttpFactory is both the PSR-17 request and stream factory. */
    private function getHttpFactory(): HttpFactory
    {
        return $this->httpFactory ??= new HttpFactory();
    }
}
