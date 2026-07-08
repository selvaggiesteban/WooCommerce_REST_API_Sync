<?php
/**
 * Payment Sync Module
 *
 * @package WooCommerceApiSync\Sync
 */

namespace WooCommerceApiSync\Sync;

defined('ABSPATH') || exit;

class PaymentSync extends AbstractSync
{
    protected string $domain = 'payments';
    protected string $endpoint = 'payment_gateways';

    protected function get_external_id(array $item): string
    {
        return $item['id'] ?? '';
    }

    protected function is_modified(array $item, object $existing): bool
    {
        return true;
    }

    public function get_all_gateways(): array
    {
        return $this->api->get_all('payment_gateways');
    }

    public function get_gateway(string $id): array
    {
        $result = $this->api->get("payment_gateways/{$id}");
        if (is_wp_error($result)) {
            throw new \Exception($result->get_error_message());
        }
        return $result;
    }

    public function update_gateway(string $id, array $data): array
    {
        $result = $this->api->put("payment_gateways/{$id}", $data);
        if (is_wp_error($result)) {
            throw new \Exception($result->get_error_message());
        }
        return $result;
    }

    public function enable_gateway(string $id): array
    {
        return $this->update_gateway($id, ['enabled' => true]);
    }

    public function disable_gateway(string $id): array
    {
        return $this->update_gateway($id, ['enabled' => false]);
    }
}
