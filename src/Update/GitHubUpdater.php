<?php
/**
 * GitHub Auto-Updater
 *
 * @package WooCommerceApiSync\Update
 */

namespace WooCommerceApiSync\Update;

use WooCommerceApiSync\Core\Config;
use WooCommerceApiSync\Utils\Logger;

defined('ABSPATH') || exit;

/**
 * Handles automatic updates from GitHub repository
 */
class GitHubUpdater
{
    /**
     * Configuration
     *
     * @var Config
     */
    private Config $config;

    /**
     * Logger instance
     *
     * @var Logger
     */
    private Logger $logger;

    /**
     * Update checker instance
     *
     * @var object|null
     */
    private ?object $updater = null;

    /**
     * Constructor
     *
     * @param Config $config Configuration instance
     */
    public function __construct(Config $config)
    {
        $this->config = $config;
        $this->logger = new Logger('updater');
    }

    /**
     * Initialize the updater
     */
    public function init(): void
    {
        if (!class_exists(\YahnisElsts\PluginUpdateChecker\v5\PucFactory::class)) {
            $this->logger->warning('Plugin Update Checker library not found');
            return;
        }

        $github_url = 'https://github.com/' . $this->config->get_github_repository();
        $check_period = $this->config->get_update_check_period();
        $github_token = $this->config->get_github_token();

        try {
            $this->updater = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
                $github_url,
                WC_API_SYNC_PLUGIN_FILE,
                'wc-api-sync',
                $check_period
            );

            // Set GitHub token if provided
            if (!empty($github_token)) {
                $this->updater->setGitHubToken($github_token);
            }

            // Configure the updater
            $this->configure_updater();

            $this->logger->info("GitHub updater initialized for {$github_url}");
        } catch (\Exception $e) {
            $this->logger->error("Failed to initialize updater: " . $e->getMessage());
        }
    }

    /**
     * Configure updater settings
     */
    private function configure_updater(): void
    {
        if (!$this->updater) {
            return;
        }

        add_filter('puc_json_metadata_wc-api-sync', [$this, 'filter_metadata'], 10, 2);

        add_action('admin_notices', [$this, 'admin_update_notice']);
    }

    /**
     * Filter update metadata
     *
     * @param object $metadata Update metadata
     * @param string $slug Plugin slug
     * @return object Modified metadata
     */
    public function filter_metadata(object $metadata, string $slug): object
    {
        // Add custom fields to metadata
        $metadata->homepage = 'https://github.com/' . $this->config->get_github_repository();
        $metadata->requires = [
            'php' => '7.4',
            'wordpress' => '6.0',
            'woocommerce' => '5.0',
        ];
        
        // Add changelog from release notes
        if (empty($metadata->sections)) {
            $metadata->sections = [];
        }
        
        $metadata->sections['changelog'] = $metadata->sections['changelog'] ?? 'See GitHub releases for changelog.';
        $metadata->sections['description'] = $metadata->sections['description'] ?? 
            'High-performance bidirectional synchronization engine for all WooCommerce domains.';

        return $metadata;
    }

    /**
     * Configure update link in plugins list
     *
     * @param array $links Existing links
     * @param string $slug Plugin slug
     * @return array Modified links
     */
    public function configure_update_link(array $links, string $slug): array
    {
        if ($slug !== 'wc-api-sync') {
            return $links;
        }

        $github_url = 'https://github.com/' . $this->config->get_github_repository();
        $links[] = sprintf(
            '<a href="%s" target="_blank">%s</a>',
            esc_url($github_url),
            __('View on GitHub', 'wc-api-sync')
        );

        return $links;
    }

    /**
     * Display admin notice for available updates
     */
    public function admin_update_notice(): void
    {
        if (!$this->updater) {
            return;
        }

        $update = $this->updater->getUpdate();
        
        if (!$update) {
            return;
        }

        $current_version = WC_API_SYNC_VERSION;
        $new_version = $update->version;
        
        printf(
            '<div class="notice notice-info"><p>%s</p></div>',
            sprintf(
                esc_html__('WooCommerce API Sync %1$s is available. %2$sView details%3$s.', 'wc-api-sync'),
                esc_html($new_version),
                '<a href="' . esc_url(admin_url('plugins.php?tab=plugin-information&plugin=wc-api-sync&TB_iframe=true&width=772&height=576')) . '" class="thickbox">',
                '</a>'
            )
        );
    }

    /**
     * Manually check for updates
     *
     * @return bool True if update available
     */
    public function check_for_updates(): bool
    {
        if (!$this->updater) {
            return false;
        }

        $update = $this->updater->checkForUpdates();
        
        return $update !== null;
    }

    /**
     * Get current version
     *
     * @return string
     */
    public function get_current_version(): string
    {
        return WC_API_SYNC_VERSION;
    }

    /**
     * Get latest version from GitHub
     *
     * @return string|false Version string or false if unavailable
     */
    public function get_latest_version(): string|false
    {
        if (!$this->updater) {
            return false;
        }

        $update = $this->updater->getUpdate();
        
        if ($update) {
            return $update->version;
        }

        return false;
    }

    /**
     * Check if update is available
     *
     * @return bool
     */
    public function is_update_available(): bool
    {
        if (!$this->updater) {
            return false;
        }

        return $this->updater->getUpdate() !== null;
    }

    /**
     * Get update info
     *
     * @return array|null
     */
    public function get_update_info(): ?array
    {
        if (!$this->updater) {
            return null;
        }

        $update = $this->updater->getUpdate();
        
        if (!$update) {
            return null;
        }

        return [
            'current_version' => WC_API_SYNC_VERSION,
            'latest_version' => $update->version,
            'download_url' => $update->download_url,
            'homepage' => $update->homepage,
            'requires_php' => $update->requires['php'] ?? '7.4',
            'requires_wp' => $update->requires['wordpress'] ?? '6.0',
            'tested_up_to' => $update->tested['wordpress'] ?? '6.4',
            'sections' => $update->sections ?? [],
        ];
    }

    /**
     * Get updater instance
     *
     * @return object|null
     */
    public function get_updater(): ?object
    {
        return $this->updater;
    }
}
