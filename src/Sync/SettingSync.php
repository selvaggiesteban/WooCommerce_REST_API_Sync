<?php
/**
 * Setting Sync Module
 *
 * @package WooCommerceApiSync\Sync
 */

namespace WooCommerceApiSync\Sync;

defined('ABSPATH') || exit;

class SettingSync extends AbstractSync
{
    protected string $domain = 'settings';
    protected string $endpoint = 'settings';

    protected function get_external_id(array $item): string
    {
        return $item['id'] ?? '';
    }

    protected function is_modified(array $item, object $existing): bool
    {
        return true;
    }

    public function get_setting_groups(): array
    {
        return $this->api->get('settings');
    }

    public function get_settings(string $group): array
    {
        return $this->api->get_all("settings/{$group}");
    }

    public function get_setting(string $group, string $id): array
    {
        $result = $this->api->get("settings/{$group}/{$id}");
        if (is_wp_error($result)) {
            throw new \Exception($result->get_error_message());
        }
        return $result;
    }

    public function update_setting(string $group, string $id, mixed $value): array
    {
        $result = $this->api->put("settings/{$group}/{$id}", ['value' => $value]);
        if (is_wp_error($result)) {
            throw new \Exception($result->get_error_message());
        }
        return $result;
    }

    public function batch_update_settings(string $group, array $updates): array
    {
        $result = $this->api->batch("settings/{$group}", [], $updates);
        if (is_wp_error($result)) {
            throw new \Exception($result->get_error_message());
        }
        return $result;
    }
}
