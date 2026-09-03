<?php

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
