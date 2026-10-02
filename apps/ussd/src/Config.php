<?php

declare(strict_types=1);

namespace Betplus\Ussd;

/**
 * No Laravel here (composer.json: bare PHP, ext-json/ext-curl only) — a plain env
 * reader, same role config/ussd.php plays on the platform side.
 */
final class Config
{
    private static bool $envLoaded = false;

    public static function loadEnv(?string $path = null): void
    {
        if (self::$envLoaded) {
            return;
        }

        $currentUser = getenv('USER') ?: (function_exists('get_current_user') ? get_current_user() : '');
        $candidates = array_filter([
            $path,
            __DIR__ . '/../.env',
            dirname(__DIR__, 2) . '/.env',
            dirname(__DIR__, 3) . '/shared/.env',
            dirname(__DIR__, 4) . '/shared/.env',
            $currentUser !== '' ? "/home/{$currentUser}/betplus/shared/.env" : null,
            '/home/betplus/betplus/shared/.env',
        ]);

        foreach ($candidates as $file) {
            if (file_exists($file) && is_readable($file)) {
                $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                if ($lines !== false) {
                    foreach ($lines as $line) {
                        $line = trim($line);
                        if ($line === '' || str_starts_with($line, '#')) {
                            continue;
                        }
                        if (str_contains($line, '=')) {
                            [$key, $val] = explode('=', $line, 2);
                            $key = trim($key);
                            $val = trim($val, " \t\n\r\0\x0B\"'");
                            if (getenv($key) === false) {
                                putenv("$key=$val");
                                $_ENV[$key] = $val;
                                $_SERVER[$key] = $val;
                            }
                        }
                    }
                }
                break;
            }
        }

        self::$envLoaded = true;
    }

    public static function platformBaseUrl(): string
    {
        self::loadEnv();

        return getenv('PLATFORM_BASE_URL') ?: 'https://api.betplus.com.ng';
    }

    public static function gatewaySharedSecret(): string
    {
        self::loadEnv();

        $secret = getenv('USSD_GATEWAY_SHARED_SECRET');
        if ($secret === false || $secret === '') {
            throw new \RuntimeException('USSD_GATEWAY_SHARED_SECRET is not configured. Set it to a strong random secret shared with the platform.');
        }

        return $secret;
    }

    public static function sessionStoreDir(): string
    {
        $configured = getenv('USSD_SESSION_STORE_DIR');
        if ($configured !== false && $configured !== '') {
            return $configured;
        }

        // Fall back to a sub-directory of sys_get_temp_dir(). Session files contain
        // raw platform access tokens; set USSD_SESSION_STORE_DIR to a dedicated path
        // outside the system temp directory (e.g. /var/lib/betplus-ussd/sessions)
        // with permissions owned exclusively by the web-server process user.
        $default = sys_get_temp_dir() . '/betplus-ussd-sessions';
        error_log('[USSD Config] USSD_SESSION_STORE_DIR is not set. Using default path "' . $default . '". Sessions contain access tokens — set a dedicated secure path in production.');

        return $default;
    }

    public static function sessionEncryptionKey(): ?string
    {
        self::loadEnv();

        $key = getenv('USSD_SESSION_ENCRYPTION_KEY');
        if ($key === false || $key === '') {
            error_log('[USSD Config] USSD_SESSION_ENCRYPTION_KEY is not set. Session files will be stored unencrypted. Set a 32-byte random secret in production.');

            return null;
        }

        return $key;
    }

    public static function requestTimeoutSeconds(): int
    {
        $value = getenv('PLATFORM_REQUEST_TIMEOUT_SECONDS');

        return $value !== false ? (int) $value : 10;
    }

    /**
     * @return string[]
     */
    public static function aggregatorIpAllowlist(): array
    {
        self::loadEnv();
        $ips = getenv('USSD_AGGREGATOR_IP_ALLOWLIST');
        if ($ips === false || trim($ips) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $ips))));
    }

    public static function aggregatorSecret(): ?string
    {
        self::loadEnv();
        $secret = getenv('USSD_AGGREGATOR_SECRET');

        return ($secret !== false && $secret !== '') ? $secret : null;
    }
}
