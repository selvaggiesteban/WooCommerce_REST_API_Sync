<?php
/**
 * Shipping Sync Module
 *
 * @package WooCommerceApiSync\Sync
 */

namespace WooCommerceApiSync\Sync;

defined('ABSPATH') || exit;

class ShippingSync extends AbstractSync
{
    protected string $domain = 'shipping';
    protected string $endpoint = 'shipping/zones';

    protected function get_external_id(array $item): string
    {
        return (string) ($item['id'] ?? '');
    }

    protected function is_modified(array $item, object $existing): bool
    {
        return true;
    }

    public function get_all_zones(): array
    {
        return $this->api->get_all('shipping/zones');
    }

    public function create_zone(array $data): array
    {
        $result = $this->api->post('shipping/zones', $data);
        if (is_wp_error($result)) {
            throw new \Exception($result->get_error_message());
        }
        return $result;
    }

    public function update_zone(int $wc_id, array $data): array
    {
        $result = $this->api->put("shipping/zones/{$wc_id}", $data);
        if (is_wp_error($result)) {
            throw new \Exception($result->get_error_message());
        }
        return $result;
    }

    public function delete_zone(int $wc_id): bool
    {
        $result = $this->api->delete("shipping/zones/{$wc_id}", ['force' => true]);
        if (is_wp_error($result)) {
            throw new \Exception($result->get_error_message());
        }
        return true;
    }

    public function get_zone_methods(int $zone_id): array
    {
        return $this->api->get_all("shipping/zones/{$zone_id}/methods");
    }

    public function add_zone_method(int $zone_id, array $data): array
    {
        $result = $this->api->post("shipping/zones/{$zone_id}/methods", $data);
        if (is_wp_error($result)) {
            throw new \Exception($result->get_error_message());
        }
        return $result;
    }

    public function update_zone_method(int $zone_id, int $method_id, array $data): array
    {
        $result = $this->api->put("shipping/zones/{$zone_id}/methods/{$method_id}", $data);
        if (is_wp_error($result)) {
            throw new \Exception($result->get_error_message());
        }
        return $result;
    }

    public function delete_zone_method(int $zone_id, int $method_id): bool
    {
        $result = $this->api->delete("shipping/zones/{$zone_id}/methods/{$method_id}", ['force' => true]);
        if (is_wp_error($result)) {
            throw new \Exception($result->get_error_message());
        }
        return true;
    }

    public function get_available_methods(): array
    {
        return $this->api->get_all('shipping_methods');
    }
}
