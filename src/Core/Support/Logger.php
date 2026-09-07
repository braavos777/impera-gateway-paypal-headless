<?php

declare(strict_types=1);

namespace ImperaPayPalHl\Core\Support;

use DateTime;
use ImperaPayPalHl\Core\Contract\LoggerInterface;
use RuntimeException;
use Throwable;

class Logger implements LoggerInterface
{
    private string $logFile;
    private string $dateFormat = 'Y-m-d H:i:s.u';

    // Logging levels for filtering and tagging
    public const DEBUG    = 'DEBUG';
    public const INFO     = 'INFO';
    public const NOTICE   = 'NOTICE';
    public const WARNING  = 'WARNING';
    public const ERROR    = 'ERROR';
    public const CRITICAL = 'CRITICAL';

    /**
     * @param string|null $filePath Path to the log file. If null, it will create in the current folder.
     */
    public function __construct(?string $filePath = null)
    {
        if ($filePath === null) {
            $filePath = __DIR__ .'/log' . '/log_' . date('Y-m-d') . '.log';
        }

        $this->logFile = $filePath;

        // Create a folder for logs if there is no one
        $dir = dirname($this->logFile);

        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true) && !is_dir($dir))
            {
                throw new RuntimeException(sprintf('Directory "%s" was not created', $dir));
            }
        }
    }

    /**
     * The main method of logging
     * @param string $level Level of Importance (INFO, ERROR)
     * @param mixed $message Message (string, array, object, exception)
     * @param array $context Additional data (variables, headers, session)
     */
    public function log(string $level, mixed $message, array $context = []): void
    {
        $timestamp = (new DateTime())->format($this->dateFormat);

        // Formatting the main message
        $formattedMessage = $this->formatMessage($message);

        // Format the context (if passed)
        $formattedContext = !empty($context) ? ' | Context: ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';

        // Assembling the final line
        $logLine = sprintf("[%s] [%s]: %s%s%s", $timestamp, $level, $formattedMessage, $formattedContext, PHP_EOL);

        // Secure write to a locked file (simultaneous write protection)
        file_put_contents($this->logFile, $logLine, FILE_APPEND | LOCK_EX);
    }

    // Quick helper methods for different levels
    public function debug(mixed $message, array $context = []): void
    {
        $this->log(self::DEBUG, $message, $context);
    }

    public function info(mixed $message, array $context = []): void
    {
        $this->log(self::INFO, $message, $context);
    }

    public function warn(mixed $message, array $context = []): void
    {
        $this->log(self::WARNING, $message, $context);
    }

    public function error(mixed $message, array $context = []): void
    {
        $this->log(self::ERROR, $message, $context);
    }

    /**
     * Converts any data type to a readable string view
     */
    private function formatMessage(mixed $message): string {
        if (is_string($message)) {
            return $message;
        }

        if ($message instanceof Throwable) {
            return sprintf("Exception %s: %s in the file %s:%s. Trace: %s",
                get_class($message), $message->getMessage(), $message->getFile(), $message->getLine(), $message->getTraceAsString()
            );
        }

        if (is_array($message) || is_object($message)) {
            return json_encode($message, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return var_export($message, true);
    }
}
