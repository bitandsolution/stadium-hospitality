<?php
/***************************************************************
*                                                              *
*   FILE: src/Services/AuthService.php - Authentication Logic  *
*                                                              *
*   Author: Antonio Tartaglia - bitAND solution                *
*   website: https://www.bitandsolution.it                     *
*   email:   info@bitandsolution.it                            *
*                                                              *
*   Owner: bitAND solution                                     *
*                                                              *
*   This is proprietary software                               *
*   developed by bitAND solution for bitAND solution           *
*                                                              *
***************************************************************/

namespace Hospitality\Utils;

class Logger {
    private static ?string $logFile = null;

    private static function init(): void {
        if (self::$logFile === null) {
            $logDir = __DIR__ . '/../../logs';
            if (!is_dir($logDir)) {
                mkdir($logDir, 0755, true);
            }
            self::$logFile = $logDir . '/app-' . date('Y-m-d') . '.log';
        }
    }

    public static function info(string $message, array $context = []): void {
        self::log('INFO', $message, $context);
    }

    public static function warning(string $message, array $context = []): void {
        self::log('WARNING', $message, $context);
    }

    public static function error(string $message, array $context = []): void {
        self::log('ERROR', $message, $context);
    }

    public static function debug(string $message, array $context = []): void {
        if (($_ENV['APP_DEBUG'] ?? 'false') === 'true') {
            self::log('DEBUG', $message, $context);
        }
    }

    private static function log(string $level, string $message, array $context = []): void {
        try {
            self::init();

            $timestamp = date('Y-m-d H:i:s');
            $contextStr = !empty($context) ? ' ' . json_encode($context) : '';
            $logLine = "[{$timestamp}] {$level}: {$message}{$contextStr}" . PHP_EOL;

            file_put_contents(self::$logFile, $logLine, FILE_APPEND | LOCK_EX);

            // Log to error_log in development
            if (($_ENV['APP_DEBUG'] ?? 'false') === 'true') {
                error_log("[Hospitality] {$level}: {$message}");
            }

        } catch (\Exception $e) {
            error_log("Logger failed: " . $e->getMessage());
            error_log("[Hospitality] {$level}: {$message}");
        }
    }
}
