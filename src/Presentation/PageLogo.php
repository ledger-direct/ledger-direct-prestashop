<?php

declare(strict_types=1);

namespace LedgerDirect\Presentation;

/**
 * Which logo the payment page shows in its header.
 *
 *   shop    the shop's own logo (PS_LOGO) — the template reads it from
 *           `$shop.logo`, the way every theme page gets it; nothing to look
 *           up here
 *   custom  a picture under the shop's img/ directory, by a path the
 *           merchant typed — validated here and only ever resolved below
 *           img/, never from a URL (a third-party host would learn the IP
 *           of every paying customer, and could break as mixed content)
 *   none    a monogram of the shop name
 *
 * The result is always rendered as an <img> with fixed maximum dimensions,
 * never as inline SVG from merchant data. When the picture cannot be
 * resolved, the monogram takes over.
 *
 * No PrestaShop dependency on purpose: the file system and the URL come in
 * as callables, so the path rules run in the unit suite.
 */
final class PageLogo
{
    public const MODE_SHOP = 'shop';
    public const MODE_CUSTOM = 'custom';
    public const MODE_NONE = 'none';

    public const MODES = [self::MODE_SHOP, self::MODE_CUSTOM, self::MODE_NONE];

    private const EXTENSIONS = ['png', 'jpg', 'jpeg', 'svg', 'webp'];

    /**
     * @param callable(string): bool $exists whether the file exists under img/
     * @param callable(string): string $urlFor the public URL of a file under img/
     *
     * @return array{mode: string, url: string|null, monogram: string}
     */
    public static function resolve(string $mode, string $path, string $shopName, callable $exists, callable $urlFor): array
    {
        if (!in_array($mode, self::MODES, true)) {
            $mode = self::MODE_SHOP;
        }

        $url = null;

        if ($mode === self::MODE_CUSTOM) {
            if (self::isValidPath($path) && $exists($path)) {
                $url = (string) $urlFor($path);
            }

            if ($url === null || $url === '') {
                $mode = self::MODE_NONE;
                $url = null;
            }
        }

        return [
            'mode' => $mode,
            'url' => $url,
            'monogram' => self::monogram($shopName),
        ];
    }

    /**
     * A path a merchant may type: relative to img/, plain characters only,
     * no parent segments, one of the picture extensions. Everything else is
     * refused before the file system is asked.
     */
    public static function isValidPath(string $path): bool
    {
        if ($path === '' || strlen($path) > 255) {
            return false;
        }

        if (preg_match('#^[A-Za-z0-9._-]+(?:/[A-Za-z0-9._-]+)*$#', $path) !== 1) {
            return false;
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return false;
            }
        }

        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        return in_array($extension, self::EXTENSIONS, true);
    }

    /** The first letter of the shop name, upper-cased, multibyte-safe; a placeholder when there is none. */
    public static function monogram(string $shopName): string
    {
        $trimmed = trim($shopName);

        if ($trimmed === '') {
            return '·';
        }

        return mb_strtoupper(mb_substr($trimmed, 0, 1));
    }
}
