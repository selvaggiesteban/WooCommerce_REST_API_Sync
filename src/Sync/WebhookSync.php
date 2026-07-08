<?php
/**
 * Webhook Sync Module
 *
 * @package WooCommerceApiSync\Sync
 */

namespace WooCommerceApiSync\Sync;

defined('ABSPATH') || exit;

class WebhookSync extends AbstractSync
{
    protected string $domain = 'webhooks';
    protected string $endpoint = 'webhooks';

    protected function get_external_id(array $item): string
    {
        return (string) ($item['id'] ?? '');
    }

    protected function is_modified(array $item, object $existing): bool
    {
        return true;
    }

    public function get_all_webhooks(): array
    {
        return $this->api->get_all('webhooks');
    }

    public function create_webhook(array $data): array
    {
        $result = $this->api->post('webhooks', $data);
        if (is_wp_error($result)) {
            throw new \Exception($result->get_error_message());
        }
        return $result;
    }

    public function update_webhook(int $wc_id, array $data): array
    {
        $result = $this->api->put("webhooks/{$wc_id}", $data);
        if (is_wp_error($result)) {
            throw new \Exception($result->get_error_message());
        }
        return $result;
    }

    public function delete_webhook(int $wc_id, bool $force = false): bool
    {
        $result = $this->api->delete("webhooks/{$wc_id}", ['force' => $force]);
        if (is_wp_error($result)) {
            throw new \Exception($result->get_error_message());
        }
        return true;
    }

    public function get_webhook_topics(): array
    {
        return [
            'order.created', 'order.updated', 'order.deleted',
            'product.created', 'product.updated', 'product.deleted',
            'customer.created', 'customer.updated', 'customer.deleted',
            'coupon.created', 'coupon.updated', 'coupon.deleted',
        ];
    }
}
