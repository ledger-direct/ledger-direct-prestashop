<?php

declare(strict_types=1);

namespace LedgerDirect\Tests\Unit;

use LedgerDirect\Presentation\PageLogo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The merchant's logo path may only ever point below img/.
 */
final class PageLogoTest extends TestCase
{
    #[DataProvider('acceptedPaths')]
    public function testAPlainPictureBelowImgIsAccepted(string $path): void
    {
        self::assertTrue(PageLogo::isValidPath($path));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function acceptedPaths(): iterable
    {
        yield 'a file' => ['logo.png'];
        yield 'a subdirectory' => ['brand/logo-2026.svg'];
        yield 'upper-case extension' => ['Logo.WEBP'];
        yield 'a dotted name' => ['my.shop.jpeg'];
    }

    #[DataProvider('refusedPaths')]
    public function testAnythingThatCouldLeaveImgOrIsNotAPictureIsRefused(string $path): void
    {
        self::assertFalse(PageLogo::isValidPath($path));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedPaths(): iterable
    {
        yield 'empty' => [''];
        yield 'parent segment' => ['../config/settings.inc.php'];
        yield 'parent segment inside' => ['brand/../../logo.png'];
        yield 'absolute' => ['/etc/passwd'];
        yield 'a URL' => ['https://example.com/logo.png'];
        yield 'a script' => ['logo.php'];
        yield 'no extension' => ['logo'];
        yield 'a space' => ['my logo.png'];
        yield 'a null byte' => ["logo.png\0.php"];
        yield 'backslash' => ['brand\\logo.png'];
    }

    public function testACustomLogoThatExistsIsResolvedToItsUrl(): void
    {
        $logo = PageLogo::resolve('custom', 'brand/logo.png', 'Fixture Shop', fn (string $p) => $p === 'brand/logo.png', fn (string $p) => '/img/' . $p);

        self::assertSame(['mode' => 'custom', 'url' => '/img/brand/logo.png', 'monogram' => 'F'], $logo);
    }

    public function testAMissingOrInvalidCustomLogoFallsBackToTheMonogram(): void
    {
        $missing = PageLogo::resolve('custom', 'gone.png', 'Fixture Shop', fn () => false, fn (string $p) => '/img/' . $p);
        $invalid = PageLogo::resolve('custom', '../x.png', 'Fixture Shop', fn () => true, fn (string $p) => '/img/' . $p);

        self::assertSame('none', $missing['mode']);
        self::assertNull($missing['url']);
        self::assertSame('none', $invalid['mode']);
    }

    public function testAnUnknownModeMeansTheShopLogo(): void
    {
        self::assertSame('shop', PageLogo::resolve('banner', '', 'x', fn () => true, fn () => '')['mode']);
    }

    public function testTheMonogramIsTheFirstLetterUpperCased(): void
    {
        self::assertSame('Ö', PageLogo::monogram('  öko-laden'));
        self::assertSame('·', PageLogo::monogram('   '));
    }
}
