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

namespace LedgerDirect\Install;

/**
 * Module files newer than the database they run against.
 *
 * PrestaShop records the version an upgrade last brought the module to in
 * `ps_module` and runs the upgrade scripts only when asked to (Module Manager
 * → Upgrade). Until then, files from a branch switch or a release zip run on
 * the old schema. That failed silently once: files 0.5.2, a table still
 * carrying `ctid` instead of `tx_ctid`, every sync aborted on the missing
 * column, and a paid order kept waiting with nothing but a generic log line
 * to show for it. This names the gap so the Back Office and the log can.
 *
 * Detection only. Running the upgrade from a front request would migrate the
 * schema in the middle of a checkout; that stays the Module Manager's job.
 */
final class PendingUpgrade
{
    private const DOMAIN = 'Modules.Ledgerdirect.Admin';

    private function __construct(
        public readonly string $installed,
        public readonly string $files,
    ) {
    }

    /**
     * @return self|null null when the database is level with the files — or
     *                   ahead of them, which an upgrade cannot fix either
     */
    public static function between(string $installed, string $files): ?self
    {
        if ($installed === '' || !version_compare($installed, $files, '<')) {
            return null;
        }

        return new self($installed, $files);
    }

    /**
     * The module instance knows its files' version, not the database's:
     * PrestaShop fills `database_version` only while listing modules on disk,
     * so the recorded version is read here, past the query cache.
     */
    public static function detect(\Module $module): ?self
    {
        $db = \Db::getInstance();
        $installed = $db->getValue(
            'SELECT `version` FROM `' . _DB_PREFIX_ . 'module` WHERE `name` = "' . $db->escape($module->name) . '"',
            false
        );

        return self::between((string) $installed, (string) $module->version);
    }

    /**
     * For a log line, where nothing but the module itself is at hand. Never
     * throws: it decorates the report of a failure and must not replace it.
     */
    public static function detectFor(string $moduleName): ?self
    {
        try {
            $module = \Module::getInstanceByName($moduleName);

            return $module instanceof \Module ? self::detect($module) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * What the merchant reads, in the Back Office language.
     */
    public function message(\Module $module): string
    {
        return $module->getTranslator()->trans(
            'The module\'s database is at version %s, its files at %s. Payments may not be confirmed until the upgrade has run: Modules → Module Manager → LedgerDirect → Upgrade.',
            [$this->installed, $this->files],
            self::DOMAIN
        );
    }

    /**
     * @return array{installed_version: string, files_version: string}
     */
    public function logContext(): array
    {
        return [
            'installed_version' => $this->installed,
            'files_version' => $this->files,
        ];
    }
}
