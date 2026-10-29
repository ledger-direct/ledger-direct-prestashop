<?php

declare(strict_types=1);

namespace LedgerDirect\Tests\Unit;

use LedgerDirect\Install\PendingUpgrade;
use PHPUnit\Framework\TestCase;

/**
 * When files and database disagree in the one direction an upgrade fixes.
 */
final class PendingUpgradeTest extends TestCase
{
    public function testFilesAheadOfTheDatabaseArePending(): void
    {
        $pendingUpgrade = PendingUpgrade::between('0.5.1', '0.5.2');

        self::assertNotNull($pendingUpgrade);
        self::assertSame('0.5.1', $pendingUpgrade->installed);
        self::assertSame('0.5.2', $pendingUpgrade->files);
        self::assertSame(
            ['installed_version' => '0.5.1', 'files_version' => '0.5.2'],
            $pendingUpgrade->logContext()
        );
    }

    public function testALevelDatabaseIsNotPending(): void
    {
        self::assertNull(PendingUpgrade::between('0.5.2', '0.5.2'));
    }

    /**
     * Files older than the database come from a downgrade; no upgrade script
     * goes backwards, so there is nothing to tell the merchant to run.
     */
    public function testADatabaseAheadOfTheFilesIsNotPending(): void
    {
        self::assertNull(PendingUpgrade::between('0.5.2', '0.5.1'));
    }

    /** Not installed: there is no row, so there is no upgrade either. */
    public function testNoRecordedVersionIsNotPending(): void
    {
        self::assertNull(PendingUpgrade::between('', '0.5.2'));
    }

    /** Versions compare as versions, not as strings: "0.5.10" is after "0.5.9". */
    public function testVersionsCompareNumerically(): void
    {
        self::assertNotNull(PendingUpgrade::between('0.5.9', '0.5.10'));
        self::assertNull(PendingUpgrade::between('0.5.10', '0.5.9'));
    }
}
