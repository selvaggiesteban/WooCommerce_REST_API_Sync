<?php
/**
 * Plugin Bootstrap
 *
 * @package WooCommerceApiSync\Core
 */

namespace WooCommerceApiSync\Core;

use WooCommerceApiSync\API\WooCommerceClient;
use WooCommerceApiSync\Features\ExternalFeedSync;
use WooCommerceApiSync\Sync\CouponSync;
use WooCommerceApiSync\Sync\CustomerSync;
use WooCommerceApiSync\Sync\MediaSync;
use WooCommerceApiSync\Sync\OrderSync;
use WooCommerceApiSync\Sync\PaymentSync;
use WooCommerceApiSync\Sync\ProductSync;
use WooCommerceApiSync\Sync\ReportSync;
use WooCommerceApiSync\Sync\SettingSync;
use WooCommerceApiSync\Sync\ShippingSync;
use WooCommerceApiSync\Sync\TaxSync;
use WooCommerceApiSync\Sync\WebhookSync;
use WooCommerceApiSync\Update\GitHubUpdater;

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
    private ?ExternalFeedSync $feed = null;
    private ?GitHubUpdater $updater = null;

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

        if (null !== $this->queue) {
            try {
                $this->feed = new ExternalFeedSync($this->config, $this->queue);
                $this->feed->init();
            } catch (\Throwable $e) {
                error_log('WC-API-Sync feed feature error: ' . $e->getMessage());
            }
        }

        if (null !== $this->api_client) {
            $this->init_sync_modules();
        }

        $this->init_admin();
        $this->init_rest_api();
        $this->init_cron();

        if (class_exists(\YahnisElsts\PluginUpdateChecker\v5\PucFactory::class)) {
            try {
                $this->updater = new GitHubUpdater($this->config);
                $this->updater->init();
            } catch (\Exception $e) {
                error_log('WC-API-Sync updater error: ' . $e->getMessage());
            }
        }
    }

    /**
     * Boot all sync modules so full sync and webhook jobs have listeners
     */
    private function init_sync_modules(): void
    {
        $modules = [
            ProductSync::class,
            OrderSync::class,
            CustomerSync::class,
            TaxSync::class,
            ShippingSync::class,
            PaymentSync::class,
            CouponSync::class,
            ReportSync::class,
            SettingSync::class,
            WebhookSync::class,
            MediaSync::class,
        ];

        foreach ($modules as $module_class) {
            try {
                $module = new $module_class($this->api_client, $this->config, $this->event_bus);
                $module->init();
            } catch (\Throwable $e) {
                error_log('WC-API-Sync module init error: ' . $e->getMessage());
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
        add_action('wp_ajax_wc_api_sync_sync_all', [$this, 'ajax_sync_all']);
        add_action('wp_ajax_wc_api_sync_check_updates', [$this, 'ajax_check_updates']);
        add_action('wp_ajax_wc_api_sync_status', [$this, 'ajax_status']);
        add_action('admin_post_wc_api_sync_run_feed', [$this, 'handle_run_feed']);
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

    /**
     * Guard for admin-ajax handlers
     */
    private function verify_ajax(): void
    {
        if (!check_ajax_referer('wc-api-sync-nonce', 'nonce', false)) {
            wp_send_json_error(['message' => __('Invalid nonce.', 'wc-api-sync')], 403);
        }

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'wc-api-sync')], 403);
        }
    }

    /**
     * Wake the queue runner without depending on wp-cron
     */
    private function poke_queue(): void
    {
        if (function_exists('as_schedule_async_action')) {
            as_schedule_async_action('wc_api_sync_process_queue');
        }
    }

    /**
     * Enqueue a job type for every enabled domain and wake the queue
     *
     * @param string $job_type Job type
     * @return int Number of jobs queued
     */
    private function enqueue_domain_jobs(string $job_type): int
    {
        $count = 0;

        foreach ($this->config->get_sync_domains() as $domain) {
            $this->queue->add_job($job_type, ['domain' => $domain]);
            $count++;
        }

        $this->poke_queue();

        return $count;
    }

    /**
     * AJAX: enqueue a full sync for every enabled domain
     */
    public function ajax_sync_all(): void
    {
        $this->verify_ajax();

        if (!$this->queue) {
            wp_send_json_error(['message' => __('Queue not initialized.', 'wc-api-sync')]);
        }

        $count = $this->enqueue_domain_jobs('full_sync');

        wp_send_json_success([
            'message' => sprintf(_n('%d sync job queued.', '%d sync jobs queued.', $count, 'wc-api-sync'), $count),
        ]);
    }

    /**
     * AJAX: run an immediate update check against GitHub
     */
    public function ajax_check_updates(): void
    {
        $this->verify_ajax();

        if (null === $this->updater) {
            wp_send_json_success([
                'message' => __('Update checker library is not installed (composer require yahnis-elsts/plugin-update-checker).', 'wc-api-sync'),
            ]);
        }

        try {
            $this->updater->check_for_updates();
        } catch (\Throwable $e) {
            wp_send_json_success([
                'message' => sprintf(__('Update check failed: %s', 'wc-api-sync'), $e->getMessage()),
            ]);
        }

        $info = $this->updater->get_update_info();

        if ($info) {
            wp_send_json_success([
                'message' => sprintf(
                    __('Version %1$s is available (installed: %2$s).', 'wc-api-sync'),
                    $info['latest_version'],
                    $info['current_version']
                ),
            ]);
        }

        wp_send_json_success([
            'message' => sprintf(__('You are up to date (v%s).', 'wc-api-sync'), WC_API_SYNC_VERSION),
        ]);
    }

    /**
     * AJAX: queue and feed status for the settings page
     */
    public function ajax_status(): void
    {
        $this->verify_ajax();

        wp_send_json_success([
            'queue' => $this->queue ? $this->queue->get_stats() : null,
            'feed' => [
                'configured' => $this->feed ? $this->feed->is_configured() : false,
                'payload_ready' => $this->feed ? $this->feed->has_payload_config() : false,
                'running' => $this->feed ? $this->feed->is_running() : false,
                'next' => $this->feed ? $this->feed->get_next_run() : false,
                'last' => $this->feed ? $this->feed->get_last_run() : null,
                'error' => $this->feed ? $this->feed->get_current_error() : null,
            ],
        ]);
    }

    /**
     * Admin-post: run the external feed now
     */
    public function handle_run_feed(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Insufficient permissions.', 'wc-api-sync'));
        }

        check_admin_referer('wc_api_sync_run_feed');

        $status = 'queued';

        if (null === $this->feed || !$this->feed->has_payload_config()) {
            $status = 'not-configured';
        } elseif ($this->feed->is_running()) {
            $status = 'running';
        } else {
            $this->feed->enqueue_job('manual');
        }

        wp_safe_redirect(add_query_arg('wc-api-sync-feed', $status, admin_url('admin.php?page=wc-api-sync')));
        exit;
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
                        'enum' => ['products', 'orders', 'customers', 'taxes', 'shipping', 'payments', 'coupons', 'reports', 'settings', 'webhooks', 'media', 'feed'],
                    ],
                ],
            ]);

            $webhook_receiver = new \WooCommerceApiSync\Webhooks\WebhookReceiver($this->config, $this->event_bus);
            $webhook_receiver->register_routes();
        });
    }

    public function handle_sync_request(\WP_REST_Request $request): \WP_REST_Response
    {
        $domain = $request->get_param('domain');

        if (!$this->queue) {
            return new \WP_REST_Response(['success' => false, 'message' => 'Queue not initialized'], 500);
        }

        if ('feed' === $domain) {
            if (null === $this->feed) {
                return new \WP_REST_Response(['success' => false, 'message' => 'Feed feature not initialized'], 500);
            }

            if (!$this->feed->has_payload_config()) {
                return new \WP_REST_Response(['success' => false, 'message' => 'External feed not configured'], 500);
            }

            $job_id = $this->feed->enqueue_job('rest');
        } else {
            $job_id = $this->queue->add_job('full_sync', ['domain' => $domain]);
        }

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

            foreach ([$this->config->get_full_sync_interval() * HOUR_IN_SECONDS, $this->config->get_incremental_sync_interval() * MINUTE_IN_SECONDS] as $interval) {
                if ($interval > 0 && !isset($schedules['wc_api_sync_ivl_' . $interval])) {
                    $schedules['wc_api_sync_ivl_' . $interval] = [
                        'interval' => $interval,
                        'display' => sprintf(__('Every %d seconds', 'wc-api-sync'), $interval),
                    ];
                }
            }

            return $schedules;
        });

        add_action('wc_api_sync_full_sync', function () {
            if ($this->queue) {
                $this->enqueue_domain_jobs('full_sync');
            }
        });

        add_action('wc_api_sync_incremental_sync', function () {
            if ($this->queue) {
                $this->queue->add_job('incremental_sync', []);
                $this->poke_queue();
            }
        });

        $this->sync_scheduled_event('wc_api_sync_full_sync', 'full', $this->config->get_full_sync_interval() * HOUR_IN_SECONDS);
        $this->sync_scheduled_event('wc_api_sync_incremental_sync', 'incremental', $this->config->get_incremental_sync_interval() * MINUTE_IN_SECONDS);
    }

    /**
     * Schedule, reschedule or clear a recurring event to match settings
     *
     * @param string $hook     Hook name
     * @param string $key      Storage key for the configured interval
     * @param int    $interval Interval in seconds (0 disables)
     */
    private function sync_scheduled_event(string $hook, string $key, int $interval): void
    {
        $stored = get_option('_wc_api_sync_cron', []);
        $stored = is_array($stored) ? $stored : [];

        if ($interval <= 0) {
            if (wp_next_scheduled($hook)) {
                wp_clear_scheduled_hook($hook);
            }
            if (isset($stored[$key])) {
                unset($stored[$key]);
                update_option('_wc_api_sync_cron', $stored, false);
            }
            return;
        }

        if (wp_next_scheduled($hook) && (($stored[$key] ?? null) === $interval)) {
            return;
        }

        wp_clear_scheduled_hook($hook);
        wp_schedule_event(time() + $interval, 'wc_api_sync_ivl_' . $interval, $hook);
        $stored[$key] = $interval;
        update_option('_wc_api_sync_cron', $stored, false);
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
