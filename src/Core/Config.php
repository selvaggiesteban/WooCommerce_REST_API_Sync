<?php
/**
 * Plugin Configuration
 *
 * @package WooCommerceApiSync\Core
 */

namespace WooCommerceApiSync\Core;

defined('ABSPATH') || exit;

/**
 * Configuration manager
 */
class Config
{
    /**
     * Option name in WordPress
     */
    const OPTION_NAME = 'wc_api_sync_settings';

    /**
     * Configuration values
     *
     * @var array
     */
    private array $values = [];

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->load();
    }

    /**
     * Load configuration from database
     */
    private function load(): void
    {
        $this->values = get_option(self::OPTION_NAME, []);
    }

    /**
     * Get a configuration value
     *
     * @param string $key Configuration key
     * @param mixed $default Default value
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    /**
     * Set a configuration value
     *
     * @param string $key Configuration key
     * @param mixed $value Configuration value
     */
    public function set(string $key, mixed $value): void
    {
        $this->values[$key] = $value;
    }

    /**
     * Save configuration to database
     */
    public function save(): void
    {
        update_option(self::OPTION_NAME, $this->values);
    }

    /**
     * Get all configuration values
     *
     * @return array
     */
    public function all(): array
    {
        return $this->values;
    }

    /**
     * Check if configuration exists
     *
     * @param string $key Configuration key
     * @return bool
     */
    public function has(string $key): bool
    {
        return isset($this->values[$key]);
    }

    /**
     * Get store URL
     *
     * @return string
     */
    public function get_store_url(): string
    {
        return $this->get('store_url', get_site_url());
    }

    /**
     * Get consumer key
     *
     * @return string
     */
    public function get_consumer_key(): string
    {
        return $this->get('consumer_key', '');
    }

    /**
     * Get consumer secret
     *
     * @return string
     */
    public function get_consumer_secret(): string
    {
        return $this->get('consumer_secret', '');
    }

    /**
     * Get API version
     *
     * @return string
     */
    public function get_api_version(): string
    {
        return $this->get('api_version', 'wc/v3');
    }

    /**
     * Get sync mode
     *
     * @return string push|pull|bidirectional
     */
    public function get_sync_mode(): string
    {
        return $this->get('sync_mode', 'bidirectional');
    }

    /**
     * Get rate limit max concurrent requests
     *
     * @return int
     */
    public function get_rate_limit_max(): int
    {
        return (int) $this->get('rate_limit_max', 5);
    }

    /**
     * Get rate limit delay between requests (ms)
     *
     * @return int
     */
    public function get_rate_limit_delay(): int
    {
        return (int) $this->get('rate_limit_delay', 100);
    }

    /**
     * Get batch size for bulk operations
     *
     * @return int
     */
    public function get_batch_size(): int
    {
        return (int) $this->get('batch_size', 100);
    }

    /**
     * Check if image optimization is enabled
     *
     * @return bool
     */
    public function is_image_optimization_enabled(): bool
    {
        return (bool) $this->get('image_optimization', true);
    }

    /**
     * Check if WebP conversion is enabled
     *
     * @return bool
     */
    public function is_webp_enabled(): bool
    {
        return (bool) $this->get('image_webp', true);
    }

    /**
     * Get image quality (1-100)
     *
     * @return int
     */
    public function get_image_quality(): int
    {
        return (int) $this->get('image_quality', 80);
    }

    /**
     * Get log level
     *
     * @return string debug|info|warning|error
     */
    public function get_log_level(): string
    {
        return $this->get('log_level', 'info');
    }

    /**
     * Get full sync interval in hours
     *
     * @return int
     */
    public function get_full_sync_interval(): int
    {
        return (int) $this->get('full_sync_interval', 6);
    }

    /**
     * Get incremental sync interval in minutes
     *
     * @return int
     */
    public function get_incremental_sync_interval(): int
    {
        return (int) $this->get('incremental_sync_interval', 5);
    }

    /**
     * Get GitHub repository URL for updates
     *
     * @return string
     */
    public function get_github_repository(): string
    {
        return $this->get('github_repository', 'selvaggiesteban/WooCommerce_REST_API_Sync');
    }

    /**
     * Get GitHub access token (optional)
     *
     * @return string
     */
    public function get_github_token(): string
    {
        return $this->get('github_token', '');
    }

    /**
     * Get update check period in hours
     *
     * @return int
     */
    public function get_update_check_period(): int
    {
        return (int) $this->get('update_check_period', 12);
    }

    /**
     * Check if debug mode is enabled
     *
     * @return bool
     */
    public function is_debug_mode(): bool
    {
        return (bool) $this->get('debug_mode', false);
    }

    /**
     * Get webhook secret for signature verification
     *
     * @return string
     */
    public function get_webhook_secret(): string
    {
        return $this->get('webhook_secret', wp_generate_password(32, false));
    }

    /**
     * Get domains to sync
     *
     * @return array
     */
    public function get_sync_domains(): array
    {
        return $this->get('sync_domains', [
            'products',
            'orders',
            'customers',
            'taxes',
            'shipping',
            'payments',
            'coupons',
        ]);
    }

    /**
     * Check if a specific domain is enabled for sync
     *
     * @param string $domain Domain name
     * @return bool
     */
    public function is_domain_enabled(string $domain): bool
    {
        $domains = $this->get_sync_domains();
        return in_array($domain, $domains, true);
    }

    /**
     * Get all settings as array
     *
     * @return array
     */
    public function to_array(): array
    {
        return [
            'store_url' => $this->get_store_url(),
            'consumer_key' => $this->get_consumer_key(),
            'consumer_secret' => $this->get_consumer_secret(),
            'api_version' => $this->get_api_version(),
            'sync_mode' => $this->get_sync_mode(),
            'rate_limit_max' => $this->get_rate_limit_max(),
            'rate_limit_delay' => $this->get_rate_limit_delay(),
            'batch_size' => $this->get_batch_size(),
            'image_optimization' => $this->is_image_optimization_enabled(),
            'image_webp' => $this->is_webp_enabled(),
            'image_quality' => $this->get_image_quality(),
            'log_level' => $this->get_log_level(),
            'full_sync_interval' => $this->get_full_sync_interval(),
            'incremental_sync_interval' => $this->get_incremental_sync_interval(),
            'github_repository' => $this->get_github_repository(),
            'update_check_period' => $this->get_update_check_period(),
            'debug_mode' => $this->is_debug_mode(),
            'sync_domains' => $this->get_sync_domains(),
        ];
    }
}
