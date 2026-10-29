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

use LedgerDirect\Install\PendingUpgrade;

/**
 * The version PrestaShop recorded for the module, against the real
 * `ps_module` row. Each test that moves the row back puts it where it was.
 */
final class PendingUpgradeTest extends IntegrationTestCase
{
    private \Module $module;
    private string $recordedVersion;

    protected function setUp(): void
    {
        $module = \Module::getInstanceByName('ledgerdirect');
        self::assertInstanceOf(\Module::class, $module);
        $this->module = $module;
        $this->recordedVersion = self::readRecordedVersion();
    }

    protected function tearDown(): void
    {
        self::recordVersion($this->recordedVersion);
    }

    public function testAnUpgradedModuleIsNotPending(): void
    {
        self::recordVersion((string) $this->module->version);

        self::assertNull(PendingUpgrade::detect($this->module));
        self::assertNull(PendingUpgrade::detectFor('ledgerdirect'));
    }

    public function testFilesAheadOfTheRecordedVersionArePending(): void
    {
        self::recordVersion('0.0.1');

        $pendingUpgrade = PendingUpgrade::detect($this->module);

        self::assertNotNull($pendingUpgrade);
        self::assertSame('0.0.1', $pendingUpgrade->installed);
        self::assertSame((string) $this->module->version, $pendingUpgrade->files);
        self::assertNotNull(PendingUpgrade::detectFor('ledgerdirect'));
    }

    /**
     * The message goes through the module's catalogue: a missing domain or
     * a broken placeholder fails here, not in the Back Office.
     */
    public function testTheMessageNamesBothVersionsAndTheWayOut(): void
    {
        self::recordVersion('0.0.1');

        $pendingUpgrade = PendingUpgrade::detect($this->module);
        self::assertNotNull($pendingUpgrade);
        $message = $pendingUpgrade->message($this->module);

        self::assertStringContainsString('0.0.1', $message);
        self::assertStringContainsString((string) $this->module->version, $message);
        self::assertStringContainsString('LedgerDirect', $message);
    }

    public function testAnUnknownModuleIsNotPending(): void
    {
        self::assertNull(PendingUpgrade::detectFor('ledgerdirect_does_not_exist'));
    }

    public static function readRecordedVersion(): string
    {
        return (string) \Db::getInstance()->getValue(
            'SELECT `version` FROM `' . _DB_PREFIX_ . 'module` WHERE `name` = "ledgerdirect"',
            false
        );
    }

    public static function recordVersion(string $version): void
    {
        \Db::getInstance()->execute(
            'UPDATE `' . _DB_PREFIX_ . 'module` SET `version` = "' . pSQL($version) . '"
             WHERE `name` = "ledgerdirect"'
        );
    }
}
