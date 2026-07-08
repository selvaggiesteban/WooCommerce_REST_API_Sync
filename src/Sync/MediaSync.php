<?php
/**
 * Media Sync Module
 *
 * @package WooCommerceApiSync\Sync
 */

namespace WooCommerceApiSync\Sync;

use WooCommerceApiSync\Image\ImageOptimizer;

defined('ABSPATH') || exit;

class MediaSync extends AbstractSync
{
    protected string $domain = 'media';
    protected string $endpoint = 'media';

    private ImageOptimizer $optimizer;

    public function __construct(\WooCommerceApiSync\API\WooCommerceClient $api, \WooCommerceApiSync\Core\Config $config, \WooCommerceApiSync\Core\EventBus $event_bus)
    {
        parent::__construct($api, $config, $event_bus);
        $this->optimizer = new ImageOptimizer($config);
    }

    protected function get_external_id(array $item): string
    {
        return (string) ($item['id'] ?? '');
    }

    protected function is_modified(array $item, object $existing): bool
    {
        return true;
    }

    public function upload_image(string $url, string $name = '', string $alt = ''): array
    {
        $image_data = $this->optimizer->process_image($url);
        
        if (empty($image_data)) {
            throw new \Exception("Failed to process image: {$url}");
        }

        $result = $this->api->post('media', [
            'file' => $image_data['content'],
            'name' => $name ?: basename(parse_url($url, PHP_URL_PATH)),
            'type' => $image_data['mime_type'],
        ]);

        if (is_wp_error($result)) {
            throw new \Exception($result->get_error_message());
        }

        return $result;
    }

    public function get_media(int $id): array
    {
        $result = $this->api->get("media/{$id}");
        if (is_wp_error($result)) {
            throw new \Exception($result->get_error_message());
        }
        return $result;
    }

    public function delete_media(int $id, bool $force = false): bool
    {
        $result = $this->api->delete("media/{$id}", ['force' => $force]);
        if (is_wp_error($result)) {
            throw new \Exception($result->get_error_message());
        }
        return true;
    }

    public function optimize_and_upload(string $url): array
    {
        $optimized = $this->optimizer->optimize($url);
        
        if (empty($optimized)) {
            throw new \Exception("Failed to optimize image: {$url}");
        }

        return $this->upload_image($optimized['path'], $optimized['name']);
    }
}
