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

namespace LedgerDirect\Cache;

use Psr\SimpleCache\InvalidArgumentException as PsrInvalidArgumentException;

/**
 * PSR-16 requires that an illegal key raises an exception implementing its own
 * marker interface, so callers can catch it without knowing the driver.
 */
final class InvalidCacheKeyException extends \InvalidArgumentException implements PsrInvalidArgumentException
{
}
