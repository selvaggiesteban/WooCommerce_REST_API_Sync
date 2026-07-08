<?php
/**
 * Abstract Sync Class
 *
 * @package WooCommerceApiSync\Sync
 */

namespace WooCommerceApiSync\Sync;

use WooCommerceApiSync\API\WooCommerceClient;
use WooCommerceApiSync\Core\Config;
use WooCommerceApiSync\Core\EventBus;
use WooCommerceApiSync\Utils\Logger;

defined('ABSPATH') || exit;

/**
 * Base class for all sync modules
 */
abstract class AbstractSync
{
    /**
     * WooCommerce API client
     *
     * @var WooCommerceClient
     */
    protected WooCommerceClient $api;

    /**
     * Configuration
     *
     * @var Config
     */
    protected Config $config;

    /**
     * Event bus
     *
     * @var EventBus
     */
    protected EventBus $event_bus;

    /**
     * Logger instance
     *
     * @var Logger
     */
    protected Logger $logger;

    /**
     * Domain name for this sync module
     *
     * @var string
     */
    protected string $domain;

    /**
     * WooCommerce resource endpoint
     *
     * @var string
     */
    protected string $endpoint;

    /**
     * State table name
     *
     * @var string
     */
    protected string $state_table;

    /**
     * Constructor
     *
     * @param WooCommerceClient $api API client
     * @param Config $config Configuration
     * @param EventBus $event_bus Event bus
     */
    public function __construct(WooCommerceClient $api, Config $config, EventBus $event_bus)
    {
        $this->api = $api;
        $this->config = $config;
        $this->event_bus = $event_bus;
        $this->logger = new Logger($this->domain ?? 'sync');
        
        global $wpdb;
        $this->state_table = $wpdb->prefix . 'wc_sync_state';
    }

    /**
     * Initialize the sync module
     */
    public function init(): void
    {
        // Register hooks
        add_action("wc_api_sync_full_sync_{$this->domain}", [$this, 'full_sync']);
        add_action("wc_api_sync_webhook_{$this->domain}.created", [$this, 'handle_created']);
        add_action("wc_api_sync_webhook_{$this->domain}.updated", [$this, 'handle_updated']);
        add_action("wc_api_sync_webhook_{$this->domain}.deleted", [$this, 'handle_deleted']);

        // Register event listeners
        $this->event_bus->on("{$this->domain}.sync.completed", [$this, 'on_sync_completed']);
    }

    /**
     * Perform full sync from WooCommerce
     *
     * @param array $payload Sync payload
     * @return array Sync results
     */
    public function full_sync(array $payload = []): array
    {
        $this->logger->info("Starting full sync for {$this->domain}");
        
        $results = [
            'created' => 0,
            'updated' => 0,
            'deleted' => 0,
            'errors' => 0,
            'duration' => 0,
        ];

        $start_time = microtime(true);

        try {
            // Get all items from WooCommerce
            $items = $this->api->get_all($this->endpoint);
            
            if (is_wp_error($items)) {
                throw new \Exception($items->get_error_message());
            }

            // Process each item
            foreach ($items as $item) {
                try {
                    $result = $this->sync_item($item);
                    $results[$result]++;
                } catch (\Exception $e) {
                    $this->logger->error("Error syncing item {$item['id']}: " . $e->getMessage());
                    $results['errors']++;
                }
            }

            // Clean up deleted items
            $deleted = $this->cleanup_deleted_items($items);
            $results['deleted'] = $deleted;

        } catch (\Exception $e) {
            $this->logger->error("Full sync failed: " . $e->getMessage());
            throw $e;
        }

        $results['duration'] = microtime(true) - $start_time;
        
        $this->logger->info("Full sync completed", $results);
        $this->event_bus->emit("{$this->domain}.sync.completed", $results);

        return $results;
    }

    /**
     * Sync a single item
     *
     * @param array $item Item data from WooCommerce
     * @return string Action taken (created|updated|unchanged)
     */
    protected function sync_item(array $item): string
    {
        global $wpdb;

        $external_id = $this->get_external_id($item);
        
        // Check if item exists in state table
        $existing = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->state_table} 
                WHERE domain = %s AND external_id = %s",
                $this->domain,
                $external_id
            )
        );

        if ($existing) {
            // Check if item has been modified
            if ($this->is_modified($item, $existing)) {
                $this->update_item($item, $existing);
                return 'updated';
            }
            return 'unchanged';
        } else {
            $this->create_item($item);
            return 'created';
        }
    }

    /**
     * Create a new item in the state table
     *
     * @param array $item Item data
     * @return int Insert ID
     */
    protected function create_item(array $item): int
    {
        global $wpdb;

        $external_id = $this->get_external_id($item);
        $wc_id = $item['id'] ?? null;

        $wpdb->insert(
            $this->state_table,
            [
                'domain' => $this->domain,
                'external_id' => $external_id,
                'wc_id' => $wc_id,
                'last_synced_at' => current_time('mysql'),
                'sync_status' => 'synced',
            ],
            ['%s', '%s', '%d', '%s', '%s']
        );

        return (int) $wpdb->insert_id;
    }

    /**
     * Update an existing item in the state table
     *
     * @param array $item Item data
     * @param object $existing Existing state record
     */
    protected function update_item(array $item, object $existing): void
    {
        global $wpdb;

        $wpdb->update(
            $this->state_table,
            [
                'wc_id' => $item['id'] ?? $existing->wc_id,
                'last_synced_at' => current_time('mysql'),
                'sync_status' => 'synced',
                'error_message' => null,
            ],
            ['id' => $existing->id],
            ['%d', '%s', '%s', '%s']
        );
    }

    /**
     * Mark an item as error in the state table
     *
     * @param string $external_id External ID
     * @param string $error_message Error message
     */
    protected function mark_error(string $external_id, string $error_message): void
    {
        global $wpdb;

        $wpdb->update(
            $this->state_table,
            [
                'sync_status' => 'error',
                'error_message' => $error_message,
                'last_synced_at' => current_time('mysql'),
            ],
            [
                'domain' => $this->domain,
                'external_id' => $external_id,
            ],
            ['%s', '%s', '%s']
        );
    }

    /**
     * Handle item created via webhook
     *
     * @param array $data Webhook data
     */
    public function handle_created(array $data): void
    {
        $this->logger->info("Webhook: item created", ['id' => $data['id'] ?? null]);
        
        try {
            $this->sync_item($data);
        } catch (\Exception $e) {
            $this->logger->error("Error handling created webhook: " . $e->getMessage());
        }
    }

    /**
     * Handle item updated via webhook
     *
     * @param array $data Webhook data
     */
    public function handle_updated(array $data): void
    {
        $this->logger->info("Webhook: item updated", ['id' => $data['id'] ?? null]);
        
        try {
            $this->sync_item($data);
        } catch (\Exception $e) {
            $this->logger->error("Error handling updated webhook: " . $e->getMessage());
        }
    }

    /**
     * Handle item deleted via webhook
     *
     * @param array $data Webhook data
     */
    public function handle_deleted(array $data): void
    {
        $this->logger->info("Webhook: item deleted", ['id' => $data['id'] ?? null]);
        
        global $wpdb;

        $wc_id = $data['id'] ?? null;
        
        if ($wc_id) {
            $wpdb->delete(
                $this->state_table,
                [
                    'domain' => $this->domain,
                    'wc_id' => $wc_id,
                ],
                ['%s', '%d']
            );
        }
    }

    /**
     * Handle sync completed event
     *
     * @param array $results Sync results
     */
    public function on_sync_completed(array $results): void
    {
        // Override in child classes if needed
    }

    /**
     * Cleanup items that no longer exist in WooCommerce
     *
     * @param array $current_items Current items from WooCommerce
     * @return int Number of items deleted
     */
    protected function cleanup_deleted_items(array $current_items): int
    {
        global $wpdb;

        // Get all synced IDs
        $synced_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT wc_id FROM {$this->state_table} 
                WHERE domain = %s AND wc_id IS NOT NULL",
                $this->domain
            )
        );

        // Get current IDs
        $current_ids = array_column($current_items, 'id');

        // Find deleted IDs
        $deleted_ids = array_diff($synced_ids, $current_ids);

        // Delete from state table
        if (!empty($deleted_ids)) {
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$this->state_table} 
                    WHERE domain = %s AND wc_id IN (" . implode(',', array_fill(0, count($deleted_ids), '%d')) . ")",
                    array_merge([$this->domain], $deleted_ids)
                )
            );
        }

        return count($deleted_ids);
    }

    /**
     * Get external ID from item
     *
     * @param array $item Item data
     * @return string External ID
     */
    abstract protected function get_external_id(array $item): string;

    /**
     * Check if item has been modified since last sync
     *
     * @param array $item Current item data
     * @param object $existing Existing state record
     * @return bool True if modified
     */
    abstract protected function is_modified(array $item, object $existing): bool;

    /**
     * Get sync statistics
     *
     * @return array
     */
    public function get_stats(): array
    {
        global $wpdb;

        $stats = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN sync_status = 'synced' THEN 1 ELSE 0 END) as synced,
                    SUM(CASE WHEN sync_status = 'pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN sync_status = 'error' THEN 1 ELSE 0 END) as errors
                FROM {$this->state_table} 
                WHERE domain = %s",
                $this->domain
            )
        );

        return [
            'domain' => $this->domain,
            'total' => (int) ($stats->total ?? 0),
            'synced' => (int) ($stats->synced ?? 0),
            'pending' => (int) ($stats->pending ?? 0),
            'errors' => (int) ($stats->errors ?? 0),
        ];
    }

    /**
     * Get domain name
     *
     * @return string
     */
    public function get_domain(): string
    {
        return $this->domain;
    }
}
