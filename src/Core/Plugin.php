<?php
/**
 * Plugin Bootstrap
 *
 * @package WooCommerceApiSync\Core
 */

namespace WooCommerceApiSync\Core;

use WooCommerceApiSync\API\WooCommerceClient;

defined('ABSPATH') || exit;

/**
 * Main Plugin Class (Singleton)
 */
class Plugin
{
    private static ?Plugin $instance = null;
    private ?Config $config = null;
    private ?WooCommerceClient $api_client = null;
    private ?EventBus $event_bus = null;
    private ?Queue $queue = null;

    public static function instance(): Plugin
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->init();
    }

    private function init(): void
    {
        $this->config = new Config();
        $this->event_bus = new EventBus();

        try {
            $this->queue = new Queue($this->config);
            $this->api_client = new WooCommerceClient($this->config);
        } catch (\Exception $e) {
            error_log('WC-API-Sync init error: ' . $e->getMessage());
        }

        $this->init_admin();
        $this->init_rest_api();
        $this->init_cron();

        if (class_exists(\YahnisElsts\PluginUpdateChecker\v5\PucFactory::class)) {
            try {
                $updater = new \WooCommerceApiSync\Update\GitHubUpdater($this->config);
                $updater->init();
            } catch (\Exception $e) {
                error_log('WC-API-Sync updater error: ' . $e->getMessage());
            }
        }
    }

    private function init_admin(): void
    {
        if (!is_admin()) {
            return;
        }

        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
    }

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

    public function render_settings_page(): void
    {
        $settings_file = WC_API_SYNC_PLUGIN_DIR . 'templates/admin/settings.php';
        if (file_exists($settings_file)) {
            include $settings_file;
        }
    }

    public function render_logs_page(): void
    {
        $logs_file = WC_API_SYNC_PLUGIN_DIR . 'templates/admin/logs.php';
        if (file_exists($logs_file)) {
            include $logs_file;
        }
    }

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

    public function handle_sync_request(\WP_REST_Request $request): \WP_REST_Response
    {
        $domain = $request->get_param('domain');

        if (!$this->queue) {
            return new \WP_REST_Response(['success' => false, 'message' => 'Queue not initialized'], 500);
        }

        $job_id = $this->queue->add_job('full_sync', ['domain' => $domain]);

        return new \WP_REST_Response([
            'success' => true,
            'job_id' => $job_id,
            'message' => sprintf(__('Sync job queued for %s', 'wc-api-sync'), $domain),
        ]);
    }

    private function init_cron(): void
    {
        add_action('wc_api_sync_process_queue', function () {
            if ($this->queue) {
                $this->queue->process();
            }
        });

        if (!wp_next_scheduled('wc_api_sync_process_queue')) {
            wp_schedule_event(time(), 'five_minutes', 'wc_api_sync_process_queue');
        }

        add_filter('cron_schedules', function ($schedules) {
            $schedules['five_minutes'] = [
                'interval' => 300,
                'display' => __('Every Five Minutes', 'wc-api-sync'),
            ];
            return $schedules;
        });
    }

    public function get_config(): Config
    {
        return $this->config;
    }

    public function get_api_client(): ?WooCommerceClient
    {
        return $this->api_client;
    }

    public function get_event_bus(): EventBus
    {
        return $this->event_bus;
    }

    public function get_queue(): ?Queue
    {
        return $this->queue;
    }
}
