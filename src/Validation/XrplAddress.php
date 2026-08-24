<?php

declare(strict_types=1);

namespace LedgerDirect\Validation;

/**
 * Format check for an XRPL classic address.
 *
 * A format check, not a checksum: it proves the string could be an XRPL
 * address, never that the account exists or that the merchant controls it.
 * Its job is to catch the mistakes people actually make in an admin form —
 * pasting an address from another chain, or a destination tag into the
 * address field.
 *
 * Deliberately its own class rather than a private method on the settings
 * form: this is the one piece of that form worth testing on its own, and it
 * has no business needing PrestaShop booted to run.
 */
final class XrplAddress
{
    /**
     * Ripple's base58 alphabet omits 0, O, I and l — the characters people
     * misread when copying an address by hand.
     */
    private const PATTERN = '/^r[1-9A-HJ-NP-Za-km-z]{24,34}$/';

    public static function isValid(string $address): bool
    {
        return preg_match(self::PATTERN, $address) === 1;
    }
}
