<?php
/**
 * Product Sync Module
 *
 * @package WooCommerceApiSync\Sync
 */

namespace WooCommerceApiSync\Sync;

defined('ABSPATH') || exit;

/**
 * Synchronizes products between external system and WooCommerce
 */
class ProductSync extends AbstractSync
{
    /**
     * Domain name
     *
     * @var string
     */
    protected string $domain = 'products';

    /**
     * WooCommerce endpoint
     *
     * @var string
     */
    protected string $endpoint = 'products';

    /**
     * Get external ID from item
     *
     * @param array $item Item data
     * @return string External ID
     */
    protected function get_external_id(array $item): string
    {
        // Use SKU as external ID, fallback to WC ID
        return $item['sku'] ?? (string) ($item['id'] ?? '');
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
     * Sync product with all its data
     *
     * @param array $product Product data from WooCommerce
     * @return array Sync result
     */
    public function sync_product(array $product): array
    {
        $result = [
            'id' => $product['id'] ?? null,
            'sku' => $product['sku'] ?? null,
            'action' => 'unchanged',
            'variations_synced' => 0,
        ];

        // Sync the main product
        $sync_result = $this->sync_item($product);
        $result['action'] = $sync_result;

        // Sync variations if variable product
        if (($product['type'] ?? '') === 'variable') {
            $variations = $this->sync_variations($product);
            $result['variations_synced'] = $variations;
        }

        return $result;
    }

    /**
     * Sync product variations
     *
     * @param array $product Parent product data
     * @return int Number of variations synced
     */
    protected function sync_variations(array $product): int
    {
        $product_id = $product['id'] ?? null;
        
        if (!$product_id) {
            return 0;
        }

        $variations = $this->api->get("products/{$product_id}/variations");
        
        if (is_wp_error($variations)) {
            $this->logger->error("Error fetching variations for product {$product_id}: " . $variations->get_error_message());
            return 0;
        }

        $synced = 0;
        
        foreach ($variations as $variation) {
            try {
                $this->sync_item($variation);
                $synced++;
            } catch (\Exception $e) {
                $this->logger->error("Error syncing variation {$variation['id']}: " . $e->getMessage());
            }
        }

        return $synced;
    }

    /**
     * Create product in WooCommerce
     *
     * @param array $product_data Product data from external system
     * @return array Created product data
     */
    public function create_product(array $product_data): array
    {
        // Transform external data to WooCommerce format
        $wc_data = $this->transform_to_wc($product_data);
        
        $result = $this->api->post('products', $wc_data);
        
        if (is_wp_error($result)) {
            throw new \Exception($result->get_error_message());
        }

        // Update state table
        $this->create_item($result);

        return $result;
    }

    /**
     * Update product in WooCommerce
     *
     * @param int $wc_id WooCommerce product ID
     * @param array $product_data Product data from external system
     * @return array Updated product data
     */
    public function update_product(int $wc_id, array $product_data): array
    {
        // Transform external data to WooCommerce format
        $wc_data = $this->transform_to_wc($product_data);
        
        $result = $this->api->put("products/{$wc_id}", $wc_data);
        
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
     * Delete product from WooCommerce
     *
     * @param int $wc_id WooCommerce product ID
     * @param bool $force Force delete (skip trash)
     * @return bool True on success
     */
    public function delete_product(int $wc_id, bool $force = false): bool
    {
        $params = $force ? ['force' => true] : [];
        
        $result = $this->api->delete("products/{$wc_id}", $params);
        
        if (is_wp_error($result)) {
            throw new \Exception($result->get_error_message());
        }

        // Remove from state table
        global $wpdb;
        $wpdb->delete(
            $this->state_table,
            [
                'domain' => $this->domain,
                'wc_id' => $wc_id,
            ],
            ['%s', '%d']
        );

        return true;
    }

    /**
     * Transform external data to WooCommerce format
     *
     * @param array $data External product data
     * @return array WooCommerce product data
     */
    protected function transform_to_wc(array $data): array
    {
        $wc_data = [
            'name' => $data['name'] ?? $data['title'] ?? '',
            'type' => $data['type'] ?? 'simple',
            'status' => $data['status'] ?? 'publish',
            'description' => $data['description'] ?? '',
            'short_description' => $data['short_description'] ?? '',
            'sku' => $data['sku'] ?? '',
            'regular_price' => $data['price'] ?? $data['regular_price'] ?? '',
            'sale_price' => $data['sale_price'] ?? '',
            'stock_quantity' => $data['stock'] ?? $data['stock_quantity'] ?? null,
            'manage_stock' => $data['manage_stock'] ?? false,
            'weight' => $data['weight'] ?? '',
            'dimensions' => [
                'length' => $data['length'] ?? '',
                'width' => $data['width'] ?? '',
                'height' => $data['height'] ?? '',
            ],
        ];

        // Handle categories
        if (!empty($data['categories'])) {
            $wc_data['categories'] = array_map(function ($cat) {
                return ['id' => $cat['id'] ?? 0, 'name' => $cat['name'] ?? ''];
            }, $data['categories']);
        }

        // Handle images
        if (!empty($data['images'])) {
            $wc_data['images'] = array_map(function ($img) {
                return ['src' => $img['url'] ?? $img['src'] ?? '', 'alt' => $img['alt'] ?? ''];
            }, $data['images']);
        }

        // Handle attributes
        if (!empty($data['attributes'])) {
            $wc_data['attributes'] = array_map(function ($attr) {
                return [
                    'name' => $attr['name'] ?? '',
                    'options' => $attr['options'] ?? $attr['values'] ?? [],
                    'visible' => $attr['visible'] ?? true,
                    'variation' => $attr['variation'] ?? false,
                ];
            }, $data['attributes']);
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

        return $wc_data;
    }

    /**
     * Transform WooCommerce data to external format
     *
     * @param array $wc_data WooCommerce product data
     * @return array External product data
     */
    public function transform_from_wc(array $wc_data): array
    {
        return [
            'external_id' => $wc_data['sku'] ?? (string) $wc_data['id'],
            'name' => $wc_data['name'] ?? '',
            'type' => $wc_data['type'] ?? 'simple',
            'sku' => $wc_data['sku'] ?? '',
            'price' => $wc_data['regular_price'] ?? '',
            'sale_price' => $wc_data['sale_price'] ?? '',
            'stock' => $wc_data['stock_quantity'] ?? null,
            'description' => $wc_data['description'] ?? '',
            'short_description' => $wc_data['short_description'] ?? '',
            'weight' => $wc_data['weight'] ?? '',
            'dimensions' => $wc_data['dimensions'] ?? [],
            'categories' => $wc_data['categories'] ?? [],
            'images' => $wc_data['images'] ?? [],
            'attributes' => $wc_data['attributes'] ?? [],
            'meta_data' => $wc_data['meta_data'] ?? [],
            'date_created' => $wc_data['date_created'] ?? '',
            'date_modified' => $wc_data['date_modified'] ?? '',
        ];
    }

    /**
     * Get product by SKU
     *
     * @param string $sku Product SKU
     * @return array|null Product data or null
     */
    public function get_product_by_sku(string $sku): ?array
    {
        $result = $this->api->get('products', ['sku' => $sku]);
        
        if (is_wp_error($result) || empty($result)) {
            return null;
        }

        return $result[0] ?? null;
    }

    /**
     * Batch sync products
     *
     * @param array $products Array of product data
     * @return array Batch results
     */
    public function batch_sync(array $products): array
    {
        $batch_size = $this->config->get_batch_size();
        $chunks = array_chunk($products, $batch_size);
        
        $results = [
            'created' => 0,
            'updated' => 0,
            'errors' => 0,
        ];

        foreach ($chunks as $chunk) {
            $create = [];
            $update = [];

            foreach ($chunk as $product) {
                $sku = $product['sku'] ?? null;
                
                if (!$sku) {
                    $results['errors']++;
                    continue;
                }

                // Check if product exists
                $existing = $this->get_product_by_sku($sku);
                
                if ($existing) {
                    $product['id'] = $existing['id'];
                    $update[] = $this->transform_to_wc($product);
                } else {
                    $create[] = $this->transform_to_wc($product);
                }
            }

            if (!empty($create) || !empty($update)) {
                $batch_result = $this->api->batch('products', $create, $update);
                
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
