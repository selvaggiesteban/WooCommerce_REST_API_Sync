<?php
/**
 * Plugin Name: WooCommerce REST API Sync
 * Plugin URI: https://github.com/selvaggiesteban/WooCommerce_REST_API_Sync
 * Description: High-performance bidirectional synchronization engine for all WooCommerce domains - Products, Orders, Customers, Taxes, Shipping, Payments, Reports, and more.
 * Version: 1.0.0
 * Author: Esteban Selvaggi
 * Author URI: https://selvaggiesteban.dev
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wc-api-sync
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 10.7
 *
 * @package WooCommerceApiSync
 */

defined('ABSPATH') || exit;

// Plugin constants
define('WC_API_SYNC_VERSION', '1.0.0');
define('WC_API_SYNC_PLUGIN_FILE', __FILE__);
define('WC_API_SYNC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WC_API_SYNC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WC_API_SYNC_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Autoload dependencies
$autoload_file = WC_API_SYNC_PLUGIN_DIR . 'vendor/autoload.php';
if (file_exists($autoload_file)) {
    require_once $autoload_file;
} else {
    // Self-contained PSR-4 autoloader fallback
    spl_autoload_register(function ($class) {
        $prefix = 'WooCommerceApiSync\\';
        $base_dir = WC_API_SYNC_PLUGIN_DIR . 'src/';

        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            return;
        }

        $relative_class = substr($class, $len);
        $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

        if (file_exists($file)) {
            require $file;
        }
    });
}

// Initialize plugin after WooCommerce is loaded
add_action('plugins_loaded', function () {
    // Check if WooCommerce is active
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function () {
            echo '<div class="error"><p>';
            echo esc_html__('WooCommerce REST API Sync requires WooCommerce to be installed and active.', 'wc-api-sync');
            echo '</p></div>';
        });
        return;
    }

    // Initialize the plugin
    \WooCommerceApiSync\Core\Plugin::instance();
});

// Register activation hook
register_activation_hook(__FILE__, function () {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();

    $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wc_sync_state (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        domain VARCHAR(50) NOT NULL,
        external_id VARCHAR(100) NOT NULL,
        wc_id BIGINT UNSIGNED DEFAULT NULL,
        last_synced_at DATETIME DEFAULT NULL,
        sync_status VARCHAR(20) DEFAULT 'pending',
        error_message TEXT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY idx_domain_external (domain, external_id),
        KEY idx_wc_id (wc_id),
        KEY idx_sync_status (sync_status)
    ) $charset");

    $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wc_sync_queue (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        job_type VARCHAR(50) NOT NULL,
        payload TEXT NOT NULL,
        priority INT DEFAULT 0,
        status VARCHAR(20) DEFAULT 'pending',
        attempts INT DEFAULT 0,
        max_attempts INT DEFAULT 3,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        processed_at DATETIME DEFAULT NULL,
        error_message TEXT DEFAULT NULL,
        PRIMARY KEY (id),
        KEY idx_status_priority (status, priority),
        KEY idx_job_type (job_type)
    ) $charset");

    $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wc_sync_mappings (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        domain VARCHAR(50) NOT NULL,
        external_field VARCHAR(100) NOT NULL,
        wc_field VARCHAR(100) NOT NULL,
        transform VARCHAR(100) DEFAULT NULL,
        is_required TINYINT(1) DEFAULT 0,
        default_value VARCHAR(255) DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY idx_domain_external (domain, external_field),
        KEY idx_domain (domain)
    ) $charset");

    $defaults = [
        'store_url' => get_site_url(),
        'consumer_key' => '',
        'consumer_secret' => '',
        'api_version' => 'wc/v3',
        'sync_mode' => 'bidirectional',
        'rate_limit_max' => 5,
        'rate_limit_delay' => 100,
        'batch_size' => 100,
        'image_optimization' => true,
        'image_webp' => true,
        'image_quality' => 80,
        'log_level' => 'info',
        'full_sync_interval' => 6,
        'incremental_sync_interval' => 5,
    ];
    $existing = get_option('wc_api_sync_settings', []);
    update_option('wc_api_sync_settings', wp_parse_args($existing, $defaults));

    flush_rewrite_rules();
});

// Register deactivation hook
register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('wc_api_sync_full_sync');
    wp_clear_scheduled_hook('wc_api_sync_incremental_sync');
    wp_clear_scheduled_hook('wc_api_sync_process_queue');
    flush_rewrite_rules();
});

// Uninstall cleanup function (must be named — register_uninstall_hook cannot use Closures)
function wc_api_sync_uninstall() {
    if (!defined('WP_UNINSTALL_PLUGIN')) {
        return;
    }
    delete_option('wc_api_sync_settings');
    delete_option('wc_api_sync_state');
    global $wpdb;
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}wc_sync_state");
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}wc_sync_queue");
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}wc_sync_mappings");
}
register_uninstall_hook(__FILE__, 'wc_api_sync_uninstall');
