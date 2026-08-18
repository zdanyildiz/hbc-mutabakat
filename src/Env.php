<?php

declare(strict_types=1);

namespace App;

class Env
{
    private static bool $loaded = false;

    /**
     * Loads .env file into $_ENV, $_SERVER and putenv.
     */
    public static function load(?string $dir = null): void
    {
        if (self::$loaded) {
            return;
        }

        $baseDir = $dir ?? dirname(__DIR__);
        $envFile = $baseDir . '/.env';

        if (!file_exists($envFile)) {
            self::$loaded = true;
            return;
        }

        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            self::$loaded = true;
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (str_contains($line, '=')) {
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value, " \t\n\r\0\x0B\"'");

                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
                putenv("{$key}={$value}");
            }
        }

        self::$loaded = true;
    }

    /**
     * Retrieves an environment variable with optional default.
     */
    public static function get(string $key, ?string $default = null): ?string
    {
        self::load();
        $val = getenv($key);
        if ($val !== false && $val !== '') {
            return (string)$val;
        }

        if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
            return (string)$_ENV[$key];
        }

        if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
            return (string)$_SERVER[$key];
        }

        return $default;
    }
}
