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
 * WC tested up to: 8.0
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
    \WooCommerceApiSync\Core\Plugin::activate();
});

// Register deactivation hook
register_deactivation_hook(__FILE__, function () {
    \WooCommerceApiSync\Core\Plugin::deactivate();
});

// Register uninstall hook
register_uninstall_hook(__FILE__, [\WooCommerceApiSync\Core\Plugin::class, 'uninstall']);
