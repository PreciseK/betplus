<?php

declare(strict_types=1);

namespace BlackRed\Logging;

use BlackRed\Bootstrap\Config;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger as MonologLogger;
use Monolog\Processor\PsrLogMessageProcessor;
use RuntimeException;

/**
 * Application logger.
 *
 * Wraps Monolog with our standard configuration: JSON-formatted lines, written
 * to a file, with a configurable minimum level. We use Monolog rather than
 * writing our own because logging done badly is a security and ops liability
 * (lost messages, race conditions on file writes, etc.).
 *
 * The wrapper exists so the rest of the codebase doesn't depend on Monolog
 * directly — easier to swap out, easier to test.
 */
final class Logger
{
    private MonologLogger $monolog;

    public function __construct(Config $config, string $channel = 'app')
    {
        $level = self::resolveLevel($config->string('LOG_LEVEL', 'info'));
        $logPath = $config->basePath() . '/' . $config->string('LOG_PATH', 'logs/app.log');

        $this->ensureLogDirectory(dirname($logPath));

        $handler = new StreamHandler($logPath, $level);
        $handler->setFormatter(new JsonFormatter(JsonFormatter::BATCH_MODE_NEWLINES, true));

        $this->monolog = new MonologLogger($channel);
        $this->monolog->pushHandler($handler);
        $this->monolog->pushProcessor(new PsrLogMessageProcessor());
    }

    private static function resolveLevel(string $name): Level
    {
        return match (strtolower($name)) {
            'debug' => Level::Debug,
            'info' => Level::Info,
            'notice' => Level::Notice,
            'warning' => Level::Warning,
            'error' => Level::Error,
            'critical' => Level::Critical,
            'alert' => Level::Alert,
            'emergency' => Level::Emergency,
            default => Level::Info,
        };
    }

    private function ensureLogDirectory(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }
        if (!@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create log directory: {$dir}");
        }
    }

    public function debug(string $message, array $context = []): void
    {
        $this->monolog->debug($message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->monolog->info($message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->monolog->warning($message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->monolog->error($message, $context);
    }

    public function critical(string $message, array $context = []): void
    {
        $this->monolog->critical($message, $context);
    }
}
