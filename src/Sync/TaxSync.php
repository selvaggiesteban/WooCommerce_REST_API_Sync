<?php
/**
 * Tax Sync Module
 *
 * @package WooCommerceApiSync\Sync
 */

namespace WooCommerceApiSync\Sync;

defined('ABSPATH') || exit;

class TaxSync extends AbstractSync
{
    protected string $domain = 'taxes';
    protected string $endpoint = 'taxes';

    protected function get_external_id(array $item): string
    {
        return (string) ($item['id'] ?? '');
    }

    protected function is_modified(array $item, object $existing): bool
    {
        return true; // Always sync taxes
    }

    public function get_all_tax_rates(): array
    {
        return $this->api->get_all('taxes');
    }

    public function create_tax_rate(array $data): array
    {
        $result = $this->api->post('taxes', $data);
        if (is_wp_error($result)) {
            throw new \Exception($result->get_error_message());
        }
        return $result;
    }

    public function update_tax_rate(int $wc_id, array $data): array
    {
        $result = $this->api->put("taxes/{$wc_id}", $data);
        if (is_wp_error($result)) {
            throw new \Exception($result->get_error_message());
        }
        return $result;
    }

    public function delete_tax_rate(int $wc_id): bool
    {
        $result = $this->api->delete("taxes/{$wc_id}", ['force' => true]);
        if (is_wp_error($result)) {
            throw new \Exception($result->get_error_message());
        }
        return true;
    }

    public function get_tax_classes(): array
    {
        return $this->api->get('taxes/classes');
    }

    public function create_tax_class(array $data): array
    {
        $result = $this->api->post('taxes/classes', $data);
        if (is_wp_error($result)) {
            throw new \Exception($result->get_error_message());
        }
        return $result;
    }

    public function delete_tax_class(string $slug): bool
    {
        $result = $this->api->delete("taxes/classes/{$slug}", ['force' => true]);
        if (is_wp_error($result)) {
            throw new \Exception($result->get_error_message());
        }
        return true;
    }
}
