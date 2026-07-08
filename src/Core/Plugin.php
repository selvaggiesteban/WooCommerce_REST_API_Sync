<?php
/**
 * Plugin Bootstrap
 *
 * @package WooCommerceApiSync\Core
 */

namespace WooCommerceApiSync\Core;

use WooCommerceApiSync\API\WooCommerceClient;
use WooCommerceApiSync\Update\GitHubUpdater;
use WooCommerceApiSync\Webhooks\WebhookReceiver;
use WooCommerceApiSync\Sync\ {
    ProductSync,
    TaxSync,
    ShippingSync,
    PaymentSync,
    OrderSync,
    CustomerSync,
    CouponSync,
    ReportSync,
    SettingSync,
    WebhookSync,
    MediaSync
};

defined('ABSPATH') || exit;

/**
 * Main Plugin Class (Singleton)
 */
class Plugin
{
    /**
     * Single instance of the class
     *
     * @var Plugin|null
     */
    private static ?Plugin $instance = null;

    /**
     * Plugin config
     *
     * @var Config
     */
    private Config $config;

    /**
     * WooCommerce API client
     *
     * @var WooCommerceClient
     */
    private WooCommerceClient $api_client;

    /**
     * Event bus for internal communication
     *
     * @var EventBus
     */
    private EventBus $event_bus;

    /**
     * Job queue
     *
     * @var Queue
     */
    private Queue $queue;

    /**
     * Get single instance
     *
     * @return Plugin
     */
    public static function instance(): Plugin
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor
     */
    private function __construct()
    {
        $this->init();
    }

    /**
     * Initialize plugin components
     */
    private function init(): void
    {
        // Load configuration
        $this->config = new Config();
        
        // Initialize event bus
        $this->event_bus = new EventBus();
        
        // Initialize queue
        $this->queue = new Queue($this->config);
        
        // Initialize API client
        $this->api_client = new WooCommerceClient($this->config);
        
        // Initialize auto-updater
        $this->init_updater();
        
        // Initialize sync modules
        $this->init_sync_modules();
        
        // Initialize webhook receiver
        $this->init_webhooks();
        
        // Register admin hooks
        $this->init_admin();
        
        // Register REST API endpoints
        $this->init_rest_api();
        
        // Schedule cron events
        $this->init_cron();
    }

    /**
     * Initialize GitHub auto-updater
     */
    private function init_updater(): void
    {
        if (class_exists(\YahnisElsts\PluginUpdateChecker\v5\PucFactory::class)) {
            $updater = new GitHubUpdater($this->config);
            $updater->init();
        }
    }

    /**
     * Initialize sync modules
     */
    private function init_sync_modules(): void
    {
        // Product sync
        $product_sync = new ProductSync($this->api_client, $this->config, $this->event_bus);
        $product_sync->init();

        // Tax sync
        $tax_sync = new TaxSync($this->api_client, $this->config, $this->event_bus);
        $tax_sync->init();

        // Shipping sync
        $shipping_sync = new ShippingSync($this->api_client, $this->config, $this->event_bus);
        $shipping_sync->init();

        // Payment sync
        $payment_sync = new PaymentSync($this->api_client, $this->config, $this->event_bus);
        $payment_sync->init();

        // Order sync
        $order_sync = new OrderSync($this->api_client, $this->config, $this->event_bus);
        $order_sync->init();

        // Customer sync
        $customer_sync = new CustomerSync($this->api_client, $this->config, $this->event_bus);
        $customer_sync->init();

        // Coupon sync
        $coupon_sync = new CouponSync($this->api_client, $this->config, $this->event_bus);
        $coupon_sync->init();

        // Report sync
        $report_sync = new ReportSync($this->api_client, $this->config, $this->event_bus);
        $report_sync->init();

        // Settings sync
        $setting_sync = new SettingSync($this->api_client, $this->config, $this->event_bus);
        $setting_sync->init();

        // Webhook sync
        $webhook_sync = new WebhookSync($this->api_client, $this->config, $this->event_bus);
        $webhook_sync->init();

        // Media sync
        $media_sync = new MediaSync($this->api_client, $this->config, $this->event_bus);
        $media_sync->init();
    }

    /**
     * Initialize webhook receiver
     */
    private function init_webhooks(): void
    {
        $webhook_receiver = new WebhookReceiver($this->config, $this->event_bus);
        $webhook_receiver->init();
    }

    /**
     * Initialize admin interface
     */
    private function init_admin(): void
    {
        if (!is_admin()) {
            return;
        }

        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
    }

    /**
     * Add admin menu pages
     */
    public function add_admin_menu(): void
    {
        add_menu_page(
            __('WC API Sync', 'wc-api-sync'),
            __('WC API Sync', 'wc-api-sync'),
            'manage_woocommerce',
            'wc-api-sync',
            [$this, 'render_settings_page'],
            'dashicons-update-alt',
            58
        );

        add_submenu_page(
            'wc-api-sync',
            __('Settings', 'wc-api-sync'),
            __('Settings', 'wc-api-sync'),
            'manage_woocommerce',
            'wc-api-sync',
            [$this, 'render_settings_page']
        );

        add_submenu_page(
            'wc-api-sync',
            __('Logs', 'wc-api-sync'),
            __('Logs', 'wc-api-sync'),
            'manage_woocommerce',
            'wc-api-sync-logs',
            [$this, 'render_logs_page']
        );
    }

    /**
     * Enqueue admin assets
     *
     * @param string $hook Current admin page
     */
    public function enqueue_admin_assets(string $hook): void
    {
        if (strpos($hook, 'wc-api-sync') === false) {
            return;
        }

        wp_enqueue_style(
            'wc-api-sync-admin',
            WC_API_SYNC_PLUGIN_URL . 'assets/css/admin.css',
            [],
            WC_API_SYNC_VERSION
        );

        wp_enqueue_script(
            'wc-api-sync-admin',
            WC_API_SYNC_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery'],
            WC_API_SYNC_VERSION,
            true
        );

        wp_localize_script('wc-api-sync-admin', 'wcApiSync', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wc-api-sync-nonce'),
        ]);
    }

    /**
     * Render settings page
     */
    public function render_settings_page(): void
    {
        include WC_API_SYNC_PLUGIN_DIR . 'templates/admin/settings.php';
    }

    /**
     * Render logs page
     */
    public function render_logs_page(): void
    {
        include WC_API_SYNC_PLUGIN_DIR . 'templates/admin/logs.php';
    }

    /**
     * Initialize REST API endpoints
     */
    private function init_rest_api(): void
    {
        add_action('rest_api_init', function () {
            register_rest_route('wc-api-sync/v1', '/sync/(?P<domain>[a-z]+)', [
                'methods' => 'POST',
                'callback' => [$this, 'handle_sync_request'],
                'permission_callback' => function () {
                    return current_user_can('manage_woocommerce');
                },
                'args' => [
                    'domain' => [
                        'required' => true,
                        'enum' => ['products', 'orders', 'customers', 'taxes', 'shipping', 'payments', 'coupons', 'reports', 'settings', 'webhooks', 'media'],
                    ],
                ],
            ]);
        });
    }

    /**
     * Handle sync REST API request
     *
     * @param \WP_REST_Request $request Request object
     * @return \WP_REST_Response
     */
    public function handle_sync_request(\WP_REST_Request $request): \WP_REST_Response
    {
        $domain = $request->get_param('domain');
        
        // Queue sync job
        $job_id = $this->queue->add_job('full_sync', [
            'domain' => $domain,
        ]);

        return new \WP_REST_Response([
            'success' => true,
            'job_id' => $job_id,
            'message' => sprintf(__('Sync job queued for %s', 'wc-api-sync'), $domain),
        ]);
    }

    /**
     * Initialize cron events
     */
    private function init_cron(): void
    {
        add_action('wc_api_sync_full_sync', [$this, 'run_full_sync']);
        add_action('wc_api_sync_incremental_sync', [$this, 'run_incremental_sync']);
        add_action('wc_api_sync_process_queue', [$this, 'process_queue']);

        if (!wp_next_scheduled('wc_api_sync_process_queue')) {
            wp_schedule_event(time(), 'minute', 'wc_api_sync_process_queue');
        }
    }

    /**
     * Run full sync for all domains
     */
    public function run_full_sync(): void
    {
        $domains = ['products', 'orders', 'customers', 'taxes', 'shipping', 'payments', 'coupons'];
        
        foreach ($domains as $domain) {
            $this->queue->add_job('full_sync', ['domain' => $domain]);
        }
    }

    /**
     * Run incremental sync
     */
    public function run_incremental_sync(): void
    {
        $this->queue->add_job('incremental_sync', []);
    }

    /**
     * Process queue jobs
     */
    public function process_queue(): void
    {
        $this->queue->process();
    }

    /**
     * Plugin activation
     */
    public static function activate(): void
    {
        // Create custom tables
        self::create_tables();
        
        // Set default options
        self::set_default_options();
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation
     */
    public static function deactivate(): void
    {
        // Clear scheduled events
        wp_clear_scheduled_hook('wc_api_sync_full_sync');
        wp_clear_scheduled_hook('wc_api_sync_incremental_sync');
        wp_clear_scheduled_hook('wc_api_sync_process_queue');
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Plugin uninstall
     */
    public static function uninstall(): void
    {
        // Check if uninstall is called from WordPress
        if (!defined('WP_UNINSTALL_PLUGIN')) {
            return;
        }

        // Delete options
        delete_option('wc_api_sync_settings');
        delete_option('wc_api_sync_state');
        
        // Drop custom tables
        global $wpdb;
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}wc_sync_state");
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}wc_sync_queue");
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}wc_sync_mappings");
    }

    /**
     * Create custom database tables
     */
    private static function create_tables(): void
    {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "
        CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wc_sync_state (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            domain VARCHAR(50) NOT NULL,
            external_id VARCHAR(100) NOT NULL,
            wc_id BIGINT UNSIGNED DEFAULT NULL,
            last_synced_at DATETIME DEFAULT NULL,
            sync_status ENUM('pending','synced','error') DEFAULT 'pending',
            error_message TEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY idx_domain_external (domain, external_id),
            KEY idx_wc_id (wc_id),
            KEY idx_sync_status (sync_status)
        ) $charset_collate;

        CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wc_sync_queue (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            job_type VARCHAR(50) NOT NULL,
            payload JSON NOT NULL,
            priority INT DEFAULT 0,
            status ENUM('pending','processing','completed','failed') DEFAULT 'pending',
            attempts INT DEFAULT 0,
            max_attempts INT DEFAULT 3,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            processed_at DATETIME DEFAULT NULL,
            error_message TEXT DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_status_priority (status, priority),
            KEY idx_job_type (job_type)
        ) $charset_collate;

        CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wc_sync_mappings (
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
        ) $charset_collate;
        ";
        
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Set default plugin options
     */
    private static function set_default_options(): void
    {
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
            'full_sync_interval' => 6, // hours
            'incremental_sync_interval' => 5, // minutes
        ];

        $existing = get_option('wc_api_sync_settings', []);
        update_option('wc_api_sync_settings', wp_parse_args($existing, $defaults));
    }

    /**
     * Get config instance
     *
     * @return Config
     */
    public function get_config(): Config
    {
        return $this->config;
    }

    /**
     * Get API client instance
     *
     * @return WooCommerceClient
     */
    public function get_api_client(): WooCommerceClient
    {
        return $this->api_client;
    }

    /**
     * Get event bus instance
     *
     * @return EventBus
     */
    public function get_event_bus(): EventBus
    {
        return $this->event_bus;
    }

    /**
     * Get queue instance
     *
     * @return Queue
     */
    public function get_queue(): Queue
    {
        return $this->queue;
    }
}
