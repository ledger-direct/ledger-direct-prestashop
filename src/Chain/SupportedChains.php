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

namespace LedgerDirect\Chain;

use Hardcastle\LedgerDirect\Core\AssetDescriptor;
use Hardcastle\LedgerDirect\Core\ChainCatalog;
use Hardcastle\LedgerDirect\Core\ChainDescriptor;

/**
 * The chains this module offers payment options on, out of the core's catalogue.
 *
 * One payment option per (chain, asset); the catalogue says which chains and assets exist,
 * the configuration says which are switched on. A chain the core knows but this module does
 * not yet carry stands in PENDING_CHAINS, and a test keeps the two in step with the
 * catalogue: a chain the core adds turns it red until the module decides.
 */
final class SupportedChains
{
    public const PENDING_CHAINS = [ChainCatalog::STELLAR];

    /**
     * The carried chains, in catalogue order.
     *
     * @return string[]
     */
    public static function chains(): array
    {
        $chains = [];
        foreach (ChainCatalog::chains() as $chain) {
            if (!in_array($chain->chain, self::PENDING_CHAINS, true)) {
                $chains[] = $chain->chain;
            }
        }

        return $chains;
    }

    public static function supports(string $chain): bool
    {
        return in_array($chain, self::chains(), true);
    }

    /**
     * Whether the module offers the asset on the chain: a carried chain with that asset in
     * the catalogue.
     */
    public static function offers(string $chain, string $asset): bool
    {
        return self::supports($chain) && ChainCatalog::chain($chain)->hasAsset($asset);
    }

    public static function descriptor(string $chain): ChainDescriptor
    {
        return ChainCatalog::chain($chain);
    }

    /**
     * The chain an asset code belongs to among the carried chains, or null. Asset codes are
     * distinct across the carried chains (XRP, RLUSD, USDC on the XRP Ledger, XAH on Xahau), so
     * a payment label that names the asset alone still names the chain.
     *
     * @return array{0: ChainDescriptor, 1: AssetDescriptor}|null
     */
    public static function chainAndAssetFor(string $asset): ?array
    {
        foreach (self::chains() as $chain) {
            $descriptor = ChainCatalog::chain($chain);
            if ($descriptor->hasAsset($asset)) {
                return [$descriptor, $descriptor->asset($asset)];
            }
        }

        return null;
    }
}
