<?php
/**
 * Order Sync Module
 *
 * @package WooCommerceApiSync\Sync
 */

namespace WooCommerceApiSync\Sync;

defined('ABSPATH') || exit;

/**
 * Synchronizes orders between external system and WooCommerce
 */
class OrderSync extends AbstractSync
{
    /**
     * Domain name
     *
     * @var string
     */
    protected string $domain = 'orders';

    /**
     * WooCommerce endpoint
     *
     * @var string
     */
    protected string $endpoint = 'orders';

    /**
     * Get external ID from item
     *
     * @param array $item Item data
     * @return string External ID
     */
    protected function get_external_id(array $item): string
    {
        // Use order number or ID as external ID
        return $item['order_key'] ?? (string) ($item['id'] ?? '');
    }

    /**
     * Check if item has been modified since last sync
     *
     * @param array $item Current item data
     * @param object $existing Existing state record
     * @return bool True if modified
     */
    protected function is_modified(array $item, object $existing): bool
    {
        if (!$existing->last_synced_at) {
            return true;
        }

        $item_modified = $item['date_modified'] ?? $item['date_created'] ?? '';
        
        if (empty($item_modified)) {
            return true;
        }

        $item_time = strtotime($item_modified);
        $sync_time = strtotime($existing->last_synced_at);

        return $item_time > $sync_time;
    }

    /**
     * Sync order with all its data
     *
     * @param array $order Order data from WooCommerce
     * @return array Sync result
     */
    public function sync_order(array $order): array
    {
        $result = [
            'id' => $order['id'] ?? null,
            'order_key' => $order['order_key'] ?? null,
            'action' => 'unchanged',
            'status' => $order['status'] ?? '',
        ];

        // Sync the main order
        $sync_result = $this->sync_item($order);
        $result['action'] = $sync_result;

        return $result;
    }

    /**
     * Create order in WooCommerce
     *
     * @param array $order_data Order data from external system
     * @return array Created order data
     */
    public function create_order(array $order_data): array
    {
        // Transform external data to WooCommerce format
        $wc_data = $this->transform_to_wc($order_data);
        
        $result = $this->api->post('orders', $wc_data);
        
        if (is_wp_error($result)) {
            throw new \Exception($result->get_error_message());
        }

        // Update state table
        $this->create_item($result);

        return $result;
    }

    /**
     * Update order in WooCommerce
     *
     * @param int $wc_id WooCommerce order ID
     * @param array $order_data Order data from external system
     * @return array Updated order data
     */
    public function update_order(int $wc_id, array $order_data): array
    {
        // Transform external data to WooCommerce format
        $wc_data = $this->transform_to_wc($order_data);
        
        $result = $this->api->put("orders/{$wc_id}", $wc_data);
        
        if (is_wp_error($result)) {
            throw new \Exception($result->get_error_message());
        }

        // Update state table
        global $wpdb;
        $wpdb->update(
            $this->state_table,
            [
                'last_synced_at' => current_time('mysql'),
                'sync_status' => 'synced',
            ],
            [
                'domain' => $this->domain,
                'wc_id' => $wc_id,
            ],
            ['%s', '%s']
        );

        return $result;
    }

    /**
     * Update order status
     *
     * @param int $wc_id WooCommerce order ID
     * @param string $status New order status
     * @return array Updated order data
     */
    public function update_status(int $wc_id, string $status): array
    {
        $valid_statuses = ['pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed'];
        
        if (!in_array($status, $valid_statuses, true)) {
            throw new \Exception("Invalid order status: {$status}");
        }

        $result = $this->api->put("orders/{$wc_id}", ['status' => $status]);
        
        if (is_wp_error($result)) {
            throw new \Exception($result->get_error_message());
        }

        return $result;
    }

    /**
     * Add order note
     *
     * @param int $wc_id WooCommerce order ID
     * @param string $note Note content
     * @param bool $customer_note Is customer note
     * @return array Created note data
     */
    public function add_note(int $wc_id, string $note, bool $customer_note = false): array
    {
        $result = $this->api->post("orders/{$wc_id}/notes", [
            'note' => $note,
            'customer_note' => $customer_note,
        ]);
        
        if (is_wp_error($result)) {
            throw new \Exception($result->get_error_message());
        }

        return $result;
    }

    /**
     * Create order refund
     *
     * @param int $wc_id WooCommerce order ID
     * @param array $refund_data Refund data
     * @return array Created refund data
     */
    public function create_refund(int $wc_id, array $refund_data): array
    {
        $result = $this->api->post("orders/{$wc_id}/refunds", $refund_data);
        
        if (is_wp_error($result)) {
            throw new \Exception($result->get_error_message());
        }

        return $result;
    }

    /**
     * Transform external data to WooCommerce format
     *
     * @param array $data External order data
     * @return array WooCommerce order data
     */
    protected function transform_to_wc(array $data): array
    {
        $wc_data = [
            'status' => $data['status'] ?? 'pending',
            'currency' => $data['currency'] ?? get_woocommerce_currency(),
            'customer_note' => $data['customer_note'] ?? '',
        ];

        // Handle billing address
        if (!empty($data['billing'])) {
            $wc_data['billing'] = [
                'first_name' => $data['billing']['first_name'] ?? '',
                'last_name' => $data['billing']['last_name'] ?? '',
                'company' => $data['billing']['company'] ?? '',
                'address_1' => $data['billing']['address_1'] ?? '',
                'address_2' => $data['billing']['address_2'] ?? '',
                'city' => $data['billing']['city'] ?? '',
                'state' => $data['billing']['state'] ?? '',
                'postcode' => $data['billing']['postcode'] ?? '',
                'country' => $data['billing']['country'] ?? '',
                'email' => $data['billing']['email'] ?? '',
                'phone' => $data['billing']['phone'] ?? '',
            ];
        }

        // Handle shipping address
        if (!empty($data['shipping'])) {
            $wc_data['shipping'] = [
                'first_name' => $data['shipping']['first_name'] ?? '',
                'last_name' => $data['shipping']['last_name'] ?? '',
                'company' => $data['shipping']['company'] ?? '',
                'address_1' => $data['shipping']['address_1'] ?? '',
                'address_2' => $data['shipping']['address_2'] ?? '',
                'city' => $data['shipping']['city'] ?? '',
                'state' => $data['shipping']['state'] ?? '',
                'postcode' => $data['shipping']['postcode'] ?? '',
                'country' => $data['shipping']['country'] ?? '',
            ];
        }

        // Handle line items
        if (!empty($data['line_items'])) {
            $wc_data['line_items'] = array_map(function ($item) {
                return [
                    'product_id' => $item['product_id'] ?? 0,
                    'variation_id' => $item['variation_id'] ?? 0,
                    'quantity' => $item['quantity'] ?? 1,
                    'sku' => $item['sku'] ?? '',
                    'name' => $item['name'] ?? '',
                    'price' => $item['price'] ?? '',
                ];
            }, $data['line_items']);
        }

        // Handle shipping lines
        if (!empty($data['shipping_lines'])) {
            $wc_data['shipping_lines'] = array_map(function ($item) {
                return [
                    'method_id' => $item['method_id'] ?? '',
                    'method_title' => $item['method_title'] ?? '',
                    'total' => $item['total'] ?? '',
                ];
            }, $data['shipping_lines']);
        }

        // Handle fee lines
        if (!empty($data['fee_lines'])) {
            $wc_data['fee_lines'] = array_map(function ($item) {
                return [
                    'name' => $item['name'] ?? '',
                    'total' => $item['total'] ?? '',
                ];
            }, $data['fee_lines']);
        }

        // Handle coupon lines
        if (!empty($data['coupon_lines'])) {
            $wc_data['coupon_lines'] = array_map(function ($item) {
                return [
                    'code' => $item['code'] ?? '',
                ];
            }, $data['coupon_lines']);
        }

        // Handle meta data
        if (!empty($data['meta_data'])) {
            $wc_data['meta_data'] = array_map(function ($meta) {
                return [
                    'key' => $meta['key'] ?? $meta['name'] ?? '',
                    'value' => $meta['value'] ?? '',
                ];
            }, $data['meta_data']);
        }

        // Handle payment method
        if (!empty($data['payment_method'])) {
            $wc_data['payment_method'] = $data['payment_method'];
            $wc_data['payment_method_title'] = $data['payment_method_title'] ?? '';
        }

        return $wc_data;
    }

    /**
     * Transform WooCommerce data to external format
     *
     * @param array $wc_data WooCommerce order data
     * @return array External order data
     */
    public function transform_from_wc(array $wc_data): array
    {
        return [
            'external_id' => $wc_data['order_key'] ?? (string) $wc_data['id'],
            'order_number' => $wc_data['number'] ?? '',
            'status' => $wc_data['status'] ?? '',
            'currency' => $wc_data['currency'] ?? '',
            'total' => $wc_data['total'] ?? '',
            'subtotal' => $wc_data['subtotal'] ?? '',
            'tax_total' => $wc_data['total_tax'] ?? '',
            'shipping_total' => $wc_data['shipping_total'] ?? '',
            'discount_total' => $wc_data['discount_total'] ?? '',
            'customer_note' => $wc_data['customer_note'] ?? '',
            'billing' => $wc_data['billing'] ?? [],
            'shipping' => $wc_data['shipping'] ?? [],
            'line_items' => $wc_data['line_items'] ?? [],
            'shipping_lines' => $wc_data['shipping_lines'] ?? [],
            'fee_lines' => $wc_data['fee_lines'] ?? [],
            'coupon_lines' => $wc_data['coupon_lines'] ?? [],
            'meta_data' => $wc_data['meta_data'] ?? [],
            'payment_method' => $wc_data['payment_method'] ?? '',
            'payment_method_title' => $wc_data['payment_method_title'] ?? '',
            'date_created' => $wc_data['date_created'] ?? '',
            'date_modified' => $wc_data['date_modified'] ?? '',
            'date_completed' => $wc_data['date_completed'] ?? '',
        ];
    }

    /**
     * Get orders by status
     *
     * @param string|array $status Order status
     * @param int $limit Number of orders to retrieve
     * @return array Orders
     */
    public function get_by_status($status, int $limit = 100): array
    {
        $status_str = is_array($status) ? implode(',', $status) : $status;
        
        return $this->api->get_all('orders', [
            'status' => $status_str,
        ], $limit);
    }

    /**
     * Get orders modified after date
     *
     * @param string $date ISO8601 date string
     * @return array Orders
     */
    public function get_modified_after(string $date): array
    {
        return $this->api->get_all('orders', [
            'modified_after' => $date,
        ]);
    }

    /**
     * Batch sync orders
     *
     * @param array $orders Array of order data
     * @return array Batch results
     */
    public function batch_sync(array $orders): array
    {
        $batch_size = $this->config->get_batch_size();
        $chunks = array_chunk($orders, $batch_size);
        
        $results = [
            'created' => 0,
            'updated' => 0,
            'errors' => 0,
        ];

        foreach ($chunks as $chunk) {
            $create = [];
            $update = [];

            foreach ($chunk as $order) {
                $external_id = $order['external_id'] ?? $order['order_key'] ?? null;
                
                if (!$external_id) {
                    $results['errors']++;
                    continue;
                }

                // Check if order exists
                global $wpdb;
                $existing = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT * FROM {$this->state_table} 
                        WHERE domain = %s AND external_id = %s",
                        $this->domain,
                        $external_id
                    )
                );

                $wc_data = $this->transform_to_wc($order);
                
                if ($existing && $existing->wc_id) {
                    $wc_data['id'] = $existing->wc_id;
                    $update[] = $wc_data;
                } else {
                    $create[] = $wc_data;
                }
            }

            if (!empty($create) || !empty($update)) {
                $batch_result = $this->api->batch('orders', $create, $update);
                
                if (!is_wp_error($batch_result)) {
                    $results['created'] += count($batch_result['create'] ?? []);
                    $results['updated'] += count($batch_result['update'] ?? []);
                } else {
                    $results['errors'] += count($chunk);
                }
            }
        }

        return $results;
    }
}
