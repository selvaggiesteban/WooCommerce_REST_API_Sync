<?php
/**
 * Coupon Sync Module
 *
 * @package WooCommerceApiSync\Sync
 */

namespace WooCommerceApiSync\Sync;

defined('ABSPATH') || exit;

class CouponSync extends AbstractSync
{
    protected string $domain = 'coupons';
    protected string $endpoint = 'coupons';

    protected function get_external_id(array $item): string
    {
        return $item['code'] ?? (string) ($item['id'] ?? '');
    }

    protected function is_modified(array $item, object $existing): bool
    {
        if (!$existing->last_synced_at) {
            return true;
        }
        return true;
    }

    public function create_coupon(array $data): array
    {
        $result = $this->api->post('coupons', $data);
        if (is_wp_error($result)) {
            throw new \Exception($result->get_error_message());
        }
        $this->create_item($result);
        return $result;
    }

    public function update_coupon(int $wc_id, array $data): array
    {
        $result = $this->api->put("coupons/{$wc_id}", $data);
        if (is_wp_error($result)) {
            throw new \Exception($result->get_error_message());
        }
        return $result;
    }

    public function delete_coupon(int $wc_id, bool $force = false): bool
    {
        $result = $this->api->delete("coupons/{$wc_id}", ['force' => $force]);
        if (is_wp_error($result)) {
            throw new \Exception($result->get_error_message());
        }
        return true;
    }

    public function get_coupon_by_code(string $code): ?array
    {
        $result = $this->api->get('coupons', ['code' => $code]);
        if (is_wp_error($result) || empty($result)) {
            return null;
        }
        return $result[0] ?? null;
    }
}
