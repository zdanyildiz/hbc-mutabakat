<?php

declare(strict_types=1);

namespace App;

class Logger
{
    private static ?string $logFile = null;

    public static function init(): void
    {
        $logDir = dirname(__DIR__) . '/var/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0777, true);
        }
        self::$logFile = $logDir . '/app.log';
    }

    public static function log(string $message): void
    {
        if (self::$logFile === null) {
            self::init();
        }
        $timestamp = date('Y-m-d H:i:s');
        $formatted = sprintf("[%s] %s\n", $timestamp, $message);
        @file_put_contents((string)self::$logFile, $formatted, FILE_APPEND);
    }
}
