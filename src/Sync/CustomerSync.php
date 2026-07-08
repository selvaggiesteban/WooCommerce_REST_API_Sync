<?php
/**
 * Customer Sync Module
 *
 * @package WooCommerceApiSync\Sync
 */

namespace WooCommerceApiSync\Sync;

defined('ABSPATH') || exit;

/**
 * Synchronizes customers between external system and WooCommerce
 */
class CustomerSync extends AbstractSync
{
    protected string $domain = 'customers';
    protected string $endpoint = 'customers';

    protected function get_external_id(array $item): string
    {
        return $item['email'] ?? (string) ($item['id'] ?? '');
    }

    protected function is_modified(array $item, object $existing): bool
    {
        if (!$existing->last_synced_at) {
            return true;
        }
        $item_modified = $item['date_modified'] ?? $item['date_created'] ?? '';
        if (empty($item_modified)) {
            return true;
        }
        return strtotime($item_modified) > strtotime($existing->last_synced_at);
    }

    public function create_customer(array $data): array
    {
        $result = $this->api->post('customers', $data);
        if (is_wp_error($result)) {
            throw new \Exception($result->get_error_message());
        }
        $this->create_item($result);
        return $result;
    }

    public function update_customer(int $wc_id, array $data): array
    {
        $result = $this->api->put("customers/{$wc_id}", $data);
        if (is_wp_error($result)) {
            throw new \Exception($result->get_error_message());
        }
        return $result;
    }

    public function get_customer_by_email(string $email): ?array
    {
        $result = $this->api->get('customers', ['email' => $email]);
        if (is_wp_error($result) || empty($result)) {
            return null;
        }
        return $result[0] ?? null;
    }
}
