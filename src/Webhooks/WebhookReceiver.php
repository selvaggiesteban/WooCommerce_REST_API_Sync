<?php
/**
 * Webhook Receiver
 *
 * @package WooCommerceApiSync\Webhooks
 */

namespace WooCommerceApiSync\Webhooks;

use WooCommerceApiSync\Core\Config;
use WooCommerceApiSync\Core\EventBus;
use WooCommerceApiSync\Utils\Logger;

defined('ABSPATH') || exit;

/**
 * Handles incoming webhooks from WooCommerce and external systems
 */
class WebhookReceiver
{
    /**
     * Configuration
     *
     * @var Config
     */
    private Config $config;

    /**
     * Event bus
     *
     * @var EventBus
     */
    private EventBus $event_bus;

    /**
     * Logger instance
     *
     * @var Logger
     */
    private Logger $logger;

    /**
     * Constructor
     *
     * @param Config $config Configuration instance
     * @param EventBus $event_bus Event bus instance
     */
    public function __construct(Config $config, EventBus $event_bus)
    {
        $this->config = $config;
        $this->event_bus = $event_bus;
        $this->logger = new Logger('webhooks');
    }

    /**
     * Initialize webhook receiver
     */
    public function init(): void
    {
        // Register REST API endpoint for webhooks
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    /**
     * Register REST API routes
     */
    public function register_routes(): void
    {
        // Webhook receiver endpoint
        register_rest_route('wc-api-sync/v1', '/webhook/(?P<topic>[a-z_.]+)', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_webhook'],
            'permission_callback' => [$this, 'verify_webhook'],
            'args' => [
                'topic' => [
                    'required' => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        // Test endpoint
        register_rest_route('wc-api-sync/v1', '/webhook/test', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_test_webhook'],
            'permission_callback' => function () {
                return current_user_can('manage_woocommerce');
            },
        ]);
    }

    /**
     * Verify webhook signature
     *
     * @param \WP_REST_Request $request Request object
     * @return bool|WP_Error True if valid, WP_Error if invalid
     */
    public function verify_webhook(\WP_REST_Request $request)
    {
        $signature = $request->get_header('X-WC-Webhook-Signature');
        
        if (empty($signature)) {
            // Check for test mode
            $test_mode = $request->get_header('X-WC-Webhook-Test');
            if ($test_mode === 'true') {
                return true;
            }
            
            return new \WP_Error(
                'webhook_signature_missing',
                'Webhook signature is missing',
                ['status' => 401]
            );
        }

        $body = $request->get_body();
        $secret = $this->config->get_webhook_secret();

        $expected_signature = base64_encode(
            hash_hmac('sha256', $body, $secret, true)
        );

        if (!hash_equals($expected_signature, $signature)) {
            $this->logger->warning('Invalid webhook signature');
            
            return new \WP_Error(
                'webhook_signature_invalid',
                'Webhook signature is invalid',
                ['status' => 401]
            );
        }

        return true;
    }

    /**
     * Handle incoming webhook
     *
     * @param \WP_REST_Request $request Request object
     * @return \WP_REST_Response
     */
    public function handle_webhook(\WP_REST_Request $request): \WP_REST_Response
    {
        $topic = $request->get_param('topic');
        $body = json_decode($request->get_body(), true) ?? [];

        $this->logger->info("Webhook received: {$topic}");

        // Extract resource and event from topic
        // Format: resource.action (e.g., order.created, product.updated)
        $parts = explode('.', $topic);
        $resource = $parts[0] ?? '';
        $action = $parts[1] ?? '';

        if (empty($resource) || empty($action)) {
            $this->logger->warning("Invalid webhook topic format: {$topic}");
            
            return new \WP_REST_Response([
                'success' => false,
                'message' => 'Invalid webhook topic format',
            ], 400);
        }

        // Process based on resource type
        try {
            $this->process_webhook($resource, $action, $body);
            
            // Emit event for internal listeners
            $this->event_bus->emit("webhook.{$resource}.{$action}", $body);
            
            // Emit specific domain event
            $this->event_bus->emit("{$resource}.{$action}", $body);

            $this->logger->info("Webhook processed successfully: {$topic}");

            return new \WP_REST_Response([
                'success' => true,
                'message' => "Webhook {$topic} processed",
            ]);

        } catch (\Exception $e) {
            $this->logger->error("Webhook processing error: " . $e->getMessage());
            
            return new \WP_REST_Response([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Process webhook based on resource and action
     *
     * @param string $resource Resource type
     * @param string $action Action type
     * @param array $data Webhook data
     */
    private function process_webhook(string $resource, string $action, array $data): void
    {
        // Queue the webhook for async processing
        $queue = \WooCommerceApiSync\Core\Plugin::instance()->get_queue();
        
        $job_id = $queue->add_job('webhook_process', [
            'topic' => "{$resource}.{$action}",
            'resource' => $resource,
            'action' => $action,
            'data' => $data,
        ], 0);

        $this->logger->debug("Webhook queued for processing", [
            'job_id' => $job_id,
            'resource' => $resource,
            'action' => $action,
        ]);
    }

    /**
     * Handle test webhook
     *
     * @param \WP_REST_Request $request Request object
     * @return \WP_REST_Response
     */
    public function handle_test_webhook(\WP_REST_Request $request): \WP_REST_Response
    {
        $body = $request->get_body();
        $data = json_decode($body, true);

        $this->logger->info('Test webhook received');

        return new \WP_REST_Response([
            'success' => true,
            'message' => 'Test webhook received',
            'data' => $data,
            'headers' => $request->get_headers(),
        ]);
    }

    /**
     * Get webhook statistics
     *
     * @return array
     */
    public function get_stats(): array
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'wc_sync_queue';
        
        $stats = $wpdb->get_row(
            "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as processed,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
            FROM {$table_name} 
            WHERE job_type = 'webhook_process'"
        );

        return [
            'total' => (int) ($stats->total ?? 0),
            'processed' => (int) ($stats->processed ?? 0),
            'failed' => (int) ($stats->failed ?? 0),
        ];
    }

    /**
     * Get webhook endpoint URL
     *
     * @return string
     */
    public function get_endpoint_url(): string
    {
        return rest_url('wc-api-sync/v1/webhook/');
    }
}
