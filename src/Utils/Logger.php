<?php
/**
 * Logger Utility
 *
 * @package WooCommerceApiSync\Utils
 */

namespace WooCommerceApiSync\Utils;

defined('ABSPATH') || exit;

/**
 * PSR-3 compatible logger for the plugin
 */
class Logger
{
    /**
     * Log levels
     */
    const LEVEL_DEBUG = 'debug';
    const LEVEL_INFO = 'info';
    const LEVEL_WARNING = 'warning';
    const LEVEL_ERROR = 'error';

    /**
     * Log level priority
     */
    private const LEVEL_PRIORITY = [
        self::LEVEL_DEBUG => 0,
        self::LEVEL_INFO => 1,
        self::LEVEL_WARNING => 2,
        self::LEVEL_ERROR => 3,
    ];

    /**
     * Channel/context for this logger
     *
     * @var string
     */
    private string $channel;

    /**
     * Minimum log level
     *
     * @var string
     */
    private string $min_level;

    /**
     * Log file path
     *
     * @var string
     */
    private string $log_file;

    /**
     * Constructor
     *
     * @param string $channel Logger channel/context
     */
    public function __construct(string $channel = 'general')
    {
        $this->channel = $channel;
        $this->min_level = get_option('wc_api_sync_settings', [])['log_level'] ?? self::LEVEL_INFO;
        $this->log_file = WP_CONTENT_DIR . '/wc-api-sync.log';
    }

    /**
     * Log a debug message
     *
     * @param string $message Log message
     * @param array $context Additional context
     */
    public function debug(string $message, array $context = []): void
    {
        $this->log(self::LEVEL_DEBUG, $message, $context);
    }

    /**
     * Log an info message
     *
     * @param string $message Log message
     * @param array $context Additional context
     */
    public function info(string $message, array $context = []): void
    {
        $this->log(self::LEVEL_INFO, $message, $context);
    }

    /**
     * Log a warning message
     *
     * @param string $message Log message
     * @param array $context Additional context
     */
    public function warning(string $message, array $context = []): void
    {
        $this->log(self::LEVEL_WARNING, $message, $context);
    }

    /**
     * Log an error message
     *
     * @param string $message Log message
     * @param array $context Additional context
     */
    public function error(string $message, array $context = []): void
    {
        $this->log(self::LEVEL_ERROR, $message, $context);
    }

    /**
     * Write a log entry
     *
     * @param string $level Log level
     * @param string $message Log message
     * @param array $context Additional context
     */
    private function log(string $level, string $message, array $context = []): void
    {
        // Check if level is enabled
        if (!isset(self::LEVEL_PRIORITY[$level]) || 
            !isset(self::LEVEL_PRIORITY[$this->min_level]) ||
            self::LEVEL_PRIORITY[$level] < self::LEVEL_PRIORITY[$this->min_level]) {
            return;
        }

        $timestamp = current_time('Y-m-d H:i:s');
        $entry = sprintf(
            "[%s] [%s] [%s] %s%s",
            $timestamp,
            strtoupper($level),
            $this->channel,
            $message,
            !empty($context) ? ' ' . wp_json_encode($context) : ''
        );

        // Write to file
        $this->write_to_file($entry);

        // Log to WordPress error log if debug mode
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("WC-API-Sync: {$entry}");
        }
    }

    /**
     * Write log entry to file
     *
     * @param string $entry Log entry
     */
    private function write_to_file(string $entry): void
    {
        $dir = dirname($this->log_file);
        
        // Create directory if it doesn't exist
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }

        // Rotate log file if it's too large (10MB)
        if (file_exists($this->log_file) && filesize($this->log_file) > 10 * 1024 * 1024) {
            $backup = $this->log_file . '.' . date('Y-m-d-H-i-s') . '.bak';
            rename($this->log_file, $backup);
            
            // Keep only last 5 backup files
            $this->cleanup_old_logs($dir);
        }

        file_put_contents(
            $this->log_file,
            $entry . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }

    /**
     * Cleanup old log files
     *
     * @param string $dir Log directory
     */
    private function cleanup_old_logs(string $dir): void
    {
        $files = glob($this->log_file . '.*.bak');
        
        if (count($files) > 5) {
            usort($files, function ($a, $b) {
                return filemtime($a) - filemtime($b);
            });
            
            $to_delete = array_slice($files, 0, count($files) - 5);
            foreach ($to_delete as $file) {
                @unlink($file);
            }
        }
    }

    /**
     * Get log entries
     *
     * @param int $limit Number of entries to retrieve
     * @param string|null $level Filter by level
     * @return array Log entries
     */
    public function get_logs(int $limit = 100, ?string $level = null): array
    {
        if (!file_exists($this->log_file)) {
            return [];
        }

        $lines = file($this->log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        if ($level) {
            $level_upper = strtoupper($level);
            $lines = array_filter($lines, function ($line) use ($level_upper) {
                return strpos($line, "[{$level_upper}]") !== false;
            });
        }

        $lines = array_reverse($lines);
        
        return array_slice($lines, 0, $limit);
    }

    /**
     * Clear log file
     */
    public function clear_logs(): void
    {
        if (file_exists($this->log_file)) {
            @unlink($this->log_file);
        }
    }

    /**
     * Get log file path
     *
     * @return string
     */
    public function get_log_file_path(): string
    {
        return $this->log_file;
    }
}
