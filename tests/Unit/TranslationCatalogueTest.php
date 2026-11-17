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

namespace LedgerDirect\Tests\Unit;

use LedgerDirect\Install\Installer;
use PHPUnit\Framework\TestCase;

/**
 * Every text a customer or merchant reads exists in every language the module
 * ships, and no translation drifted away from its source.
 *
 * The code is the source of truth: every trans() call with its domain in the
 * PHP files and every {l s='…' d='…'} in the templates is collected, and each
 * locale's XLIFF file of that domain must carry it. English leftovers slipped
 * through in other plugins before, so a target equal to its source, or with an
 * everyday English word in it, fails unless it is a name on the short list below.
 */
final class TranslationCatalogueTest extends TestCase
{
    /** The languages the module ships; en-US is the source. */
    private const LOCALES = ['en-US', 'de-DE', 'fr-FR', 'nl-NL', 'es-ES', 'pt-PT'];

    /** Texts that read the same in every language: names, and the glossary's English terms. */
    private const SAME_IN_EVERY_LANGUAGE = [
        'LedgerDirect',
        'XRPL Mainnet',
        'XRPL Testnet',
        'Xahau Mainnet',
        'Xahau Testnet',
        '1 %asset% = %rate% %currency%',
        'Destination tag',
        'Transaction', // fr
        'Logo', // de, fr, nl
        'No', // es
    ];

    /** Everyday English words none of the five other languages uses; one in a target is a leftover. */
    private const ENGLISH_WORDS = ['the', 'and', 'your', 'please', 'with', 'from', 'this', 'that', 'will', 'only', 'cannot', 'payment', 'amount', 'order', 'received'];

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    public function testEveryLocaleHasACatalogueForEveryDomain(): void
    {
        $directories = array_map('basename', glob(self::root() . '/translations/*', GLOB_ONLYDIR) ?: []);
        sort($directories);
        $expected = self::LOCALES;
        sort($expected);
        self::assertSame($expected, $directories, 'translations/ holds exactly the shipped locales');

        foreach (self::LOCALES as $locale) {
            self::assertFileExists(self::root() . '/translations/' . $locale . '/index.php');
            foreach (array_keys(self::usedStrings()) as $domain) {
                self::assertFileExists(self::catalogueFile($domain, $locale));
            }
        }
    }

    public function testEveryTextInTheCodeIsInEveryCatalogue(): void
    {
        $missing = [];
        foreach (self::usedStrings() as $domain => $sources) {
            foreach (self::LOCALES as $locale) {
                $catalogue = self::catalogue($domain, $locale);
                foreach ($sources as $source => $files) {
                    if (!array_key_exists($source, $catalogue)) {
                        $missing[] = sprintf('%s %s: "%s" (%s)', $locale, $domain, $source, implode(', ', $files));
                    }
                }
            }
        }

        self::assertSame([], $missing);
    }

    public function testNoCatalogueCarriesATextTheCodeNoLongerUses(): void
    {
        $unused = [];
        foreach (self::usedStrings() as $domain => $sources) {
            foreach (self::LOCALES as $locale) {
                foreach (array_keys(self::catalogue($domain, $locale)) as $source) {
                    if (!array_key_exists($source, $sources)) {
                        $unused[] = sprintf('%s %s: "%s"', $locale, $domain, $source);
                    }
                }
            }
        }

        self::assertSame([], $unused);
    }

    public function testEveryUnitIsKeyedByTheMd5OfItsSourceAndHasATarget(): void
    {
        $problems = [];
        foreach (self::allCatalogues() as $label => [$locale, $units]) {
            foreach ($units as $source => [$id, $target]) {
                if ($id !== md5($source)) {
                    $problems[] = sprintf('%s: id %s for "%s"', $label, $id, $source);
                }
                if (trim($target) === '') {
                    $problems[] = sprintf('%s: empty target for "%s"', $label, $source);
                }
                if ($locale === 'en-US' && $target !== $source) {
                    $problems[] = sprintf('%s: the English target differs from its source "%s"', $label, $source);
                }
            }
        }

        self::assertSame([], $problems);
    }

    public function testEveryTargetKeepsThePlaceholdersAndTagsOfItsSource(): void
    {
        $problems = [];
        foreach (self::allCatalogues() as $label => [, $units]) {
            foreach ($units as $source => [, $target]) {
                if (self::placeholders($source) !== self::placeholders($target)) {
                    $problems[] = sprintf('%s: "%s" -> "%s"', $label, $source, $target);
                }
            }
        }

        self::assertSame([], $problems);
    }

    public function testNoTargetIsLeftInEnglish(): void
    {
        $pattern = '/\b(' . implode('|', self::ENGLISH_WORDS) . ')\b/i';
        $problems = [];
        foreach (self::allCatalogues() as $label => [$locale, $units]) {
            if ($locale === 'en-US') {
                continue;
            }
            foreach ($units as $source => [, $target]) {
                if ($target === $source && !in_array($source, self::SAME_IN_EVERY_LANGUAGE, true)) {
                    $problems[] = sprintf('%s: untranslated "%s"', $label, $source);
                } elseif (preg_match($pattern, str_replace(self::placeholders($target), '', $target), $match) === 1) {
                    $problems[] = sprintf('%s: English "%s" in "%s"', $label, $match[1], $target);
                }
            }
        }

        self::assertSame([], $problems);
    }

    /**
     * Order states are database rows named at install time, not catalogue
     * entries; they need a name in every shipped language all the same.
     */
    public function testTheOrderStatesHaveANameInEveryLanguage(): void
    {
        foreach (Installer::ORDER_STATE_NAMES as $key => $names) {
            foreach (self::LOCALES as $locale) {
                $iso = substr($locale, 0, 2);
                self::assertNotEmpty($names[$iso] ?? '', sprintf('%s has no name for %s', $key, $iso));
                if ($iso !== 'en') {
                    self::assertNotSame($names['en'], $names[$iso], sprintf('%s is English in %s', $key, $iso));
                }
            }
        }
    }

    /**
     * `%s`, `%d`, `%1$s`, `%name%` and HTML tags, as a sorted list: the count
     * matters, the order may change.
     *
     * @return string[]
     */
    private static function placeholders(string $text): array
    {
        preg_match_all('/%[a-z_]+%|%(?:\d+\$)?[sd]|<\/?[a-z][^>]*>/i', $text, $matches);
        $found = $matches[0];
        sort($found);

        return $found;
    }

    /**
     * @return array<string, array{string, array<string, array{string, string}>}> "<locale> <domain>" => [locale, units]
     */
    private static function allCatalogues(): array
    {
        $all = [];
        foreach (array_keys(self::usedStrings()) as $domain) {
            foreach (self::LOCALES as $locale) {
                $all[$locale . ' ' . $domain] = [$locale, self::catalogue($domain, $locale)];
            }
        }

        return $all;
    }

    private static function catalogueFile(string $domain, string $locale): string
    {
        return sprintf('%s/translations/%s/%s.%s.xlf', self::root(), $locale, str_replace('.', '', $domain), $locale);
    }

    /**
     * @return array<string, array{string, string}> source => [id, target]
     */
    private static function catalogue(string $domain, string $locale): array
    {
        $file = self::catalogueFile($domain, $locale);
        if (!is_file($file)) {
            return [];
        }

        $document = new \DOMDocument();
        self::assertTrue($document->load($file), $file . ' is well-formed XML');

        $units = [];
        foreach ($document->getElementsByTagName('trans-unit') as $unit) {
            $source = $unit->getElementsByTagName('source')->item(0);
            $target = $unit->getElementsByTagName('target')->item(0);
            self::assertNotNull($source, $file);
            self::assertArrayNotHasKey($source->textContent, $units, $file . ' has the source twice: ' . $source->textContent);
            $units[$source->textContent] = [$unit->getAttribute('id'), $target === null ? '' : $target->textContent];
        }

        return $units;
    }

    /**
     * Every translatable text in the code, by domain.
     *
     * @return array<string, array<string, string[]>> domain => source => files
     */
    private static function usedStrings(): array
    {
        static $used = null;
        if ($used !== null) {
            return $used;
        }

        $found = [];
        $root = self::root();
        foreach (self::files(['ledgerdirect.php', 'src', 'controllers', 'upgrade'], 'php') as $file) {
            foreach (self::phpCalls($file) as [$source, $domain]) {
                $found[$domain][$source][] = substr($file, strlen($root) + 1);
            }
        }
        foreach (self::files(['views'], 'tpl') as $file) {
            foreach (self::templateCalls($file) as [$source, $domain]) {
                $found[$domain][$source][] = substr($file, strlen($root) + 1);
            }
        }
        foreach ($found as &$sources) {
            $sources = array_map(static fn (array $files): array => array_values(array_unique($files)), $sources);
        }
        unset($sources);

        self::assertNotEmpty($found);

        return $used = $found;
    }

    /**
     * @param string[] $paths relative to the module root
     *
     * @return string[]
     */
    private static function files(array $paths, string $extension): array
    {
        $files = [];
        foreach ($paths as $path) {
            $absolute = self::root() . '/' . $path;
            if (is_file($absolute)) {
                $files[] = $absolute;
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($absolute, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === $extension) {
                    $files[] = $file->getPathname();
                }
            }
        }
        sort($files);

        return $files;
    }

    /**
     * Every `trans('literal', [...], domain)` in a PHP file. A call whose text
     * is not a literal, or that names no domain, fails: the catalogues could
     * not be checked for it.
     *
     * @return list<array{string, string}> [source, domain]
     */
    private static function phpCalls(string $file): array
    {
        $code = (string) file_get_contents($file);
        $domainConstant = preg_match('/const DOMAIN = \'([^\']+)\'/', $code, $match) === 1 ? $match[1] : null;
        $tokens = array_values(array_filter(
            token_get_all($code),
            static fn ($token): bool => !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ));

        $calls = [];
        $count = count($tokens);
        for ($i = 0; $i < $count - 1; ++$i) {
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_STRING || $tokens[$i][1] !== 'trans' || $tokens[$i + 1] !== '(') {
                continue;
            }
            // A declaration (function trans(...)) is not a call.
            if (is_array($tokens[$i - 1] ?? null) && $tokens[$i - 1][0] === T_FUNCTION) {
                continue;
            }

            // Split the arguments at depth one.
            $arguments = [[]];
            $depth = 0;
            for ($j = $i + 1; $j < $count; ++$j) {
                $text = is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
                if (in_array($text, ['(', '[', '{'], true) || (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                    ++$depth;
                    if ($depth === 1) {
                        continue;
                    }
                } elseif (in_array($text, [')', ']', '}'], true)) {
                    --$depth;
                    if ($depth === 0) {
                        break;
                    }
                } elseif ($text === ',' && $depth === 1) {
                    $arguments[] = [];
                    continue;
                }
                $arguments[count($arguments) - 1][] = $tokens[$j];
            }

            $where = sprintf('%s:%d', basename($file), $tokens[$i][2]);
            $first = $arguments[0];
            self::assertTrue(
                count($first) === 1 && is_array($first[0]) && $first[0][0] === T_CONSTANT_ENCAPSED_STRING,
                $where . ': trans() needs a literal text so the catalogues can be checked'
            );
            self::assertArrayHasKey(2, $arguments, $where . ': trans() names no domain');

            $domainTokens = array_map(static fn ($token): string => is_array($token) ? $token[1] : $token, $arguments[2]);
            $domainCode = implode('', $domainTokens);
            if ($domainCode === 'self::DOMAIN' && $domainConstant !== null) {
                $domain = $domainConstant;
            } else {
                self::assertSame(1, preg_match('/^\'(Modules\.Ledgerdirect\.[A-Za-z]+)\'$/', $domainCode, $domainMatch), $where . ': unknown domain ' . $domainCode);
                $domain = $domainMatch[1];
            }

            $calls[] = [self::phpString($first[0][1]), $domain];
        }

        return $calls;
    }

    private static function phpString(string $literal): string
    {
        $quote = $literal[0];
        $body = substr($literal, 1, -1);

        return $quote === '\''
            ? str_replace(['\\\'', '\\\\'], ['\'', '\\'], $body)
            : stripcslashes($body);
    }

    /**
     * Every `{l s='…' d='…'}` in a Smarty template. The tag is read up to its
     * matching brace, so sprintf arguments with Smarty variables in them do
     * not end it early. A tag without a domain fails.
     *
     * @return list<array{string, string}> [source, domain]
     */
    private static function templateCalls(string $file): array
    {
        $template = (string) file_get_contents($file);
        $calls = [];
        $offset = 0;
        while (preg_match('/\{l\s/', $template, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $start = $match[0][1];
            $depth = 0;
            $quote = null;
            $end = $start;
            for ($k = $start, $length = strlen($template); $k < $length; ++$k) {
                $char = $template[$k];
                if ($quote !== null) {
                    if ($char === '\\') {
                        ++$k;
                    } elseif ($char === $quote) {
                        $quote = null;
                    }
                    continue;
                }
                if ($char === '\'' || $char === '"') {
                    $quote = $char;
                } elseif ($char === '{') {
                    ++$depth;
                } elseif ($char === '}') {
                    --$depth;
                    if ($depth === 0) {
                        $end = $k;
                        break;
                    }
                }
            }
            $tag = substr($template, $start, $end - $start + 1);
            $offset = $end + 1;

            $line = substr_count(substr($template, 0, $start), "\n") + 1;
            $where = sprintf('%s:%d', basename($file), $line);
            self::assertSame(1, preg_match('/^\{l\s+s=(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")/s', $tag, $sourceMatch), $where . ': {l} needs a literal s=');
            self::assertSame(1, preg_match('/\sd=([\'"])([^\'"]+)\1/', $tag, $domainMatch), $where . ': {l} names no domain');

            $literal = $sourceMatch[1];
            $source = str_replace('\\' . $literal[0], $literal[0], substr($literal, 1, -1));
            $calls[] = [$source, $domainMatch[2]];
        }

        return $calls;
    }
}
