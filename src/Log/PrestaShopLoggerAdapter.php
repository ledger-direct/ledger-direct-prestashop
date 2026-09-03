<?php

declare(strict_types=1);

namespace LedgerDirect\Log;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

/**
 * PSR-3 sink for the core, writing into PrestaShop's own log (Advanced
 * Parameters → Logs). The core logs every swallowed oracle/sync failure
 * through this port, so a merchant seeing "no price available" has somewhere
 * to find out which oracle actually broke.
 */
final class PrestaShopLoggerAdapter extends AbstractLogger
{
    /** PrestaShop severities: 1 informative, 2 warning, 3 error, 4 major. */
    private const SEVERITY_BY_LEVEL = [
        LogLevel::DEBUG => 1,
        LogLevel::INFO => 1,
        LogLevel::NOTICE => 1,
        LogLevel::WARNING => 2,
        LogLevel::ERROR => 3,
        LogLevel::CRITICAL => 3,
        LogLevel::ALERT => 4,
        LogLevel::EMERGENCY => 4,
    ];

    /**
     * @param array<string, mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $severity = self::SEVERITY_BY_LEVEL[(string) $level] ?? 1;

        $line = (string) $message;
        if ($context !== []) {
            $line .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        }

        \PrestaShopLogger::addLog('[LedgerDirect] ' . $line, $severity);
    }
}
