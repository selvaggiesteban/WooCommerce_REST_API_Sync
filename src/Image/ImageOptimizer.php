<?php
/**
 * Image Optimizer
 *
 * @package WooCommerceApiSync\Image
 */

namespace WooCommerceApiSync\Image;

use WooCommerceApiSync\Core\Config;
use WooCommerceApiSync\Utils\Logger;

defined('ABSPATH') || exit;

class ImageOptimizer
{
    private Config $config;
    private Logger $logger;
    private string $temp_dir;

    public function __construct(Config $config)
    {
        $this->config = $config;
        $this->logger = new Logger('image');
        $this->temp_dir = wp_upload_dir()['basedir'] . '/wc-api-sync-temp';
        
        if (!is_dir($this->temp_dir)) {
            wp_mkdir_p($this->temp_dir);
        }
    }

    public function process_image(string $url): ?array
    {
        if (!$this->config->is_image_optimization_enabled()) {
            return $this->download_image($url);
        }

        $temp_file = $this->download_to_temp($url);
        
        if (!$temp_file) {
            return null;
        }

        $optimized = $this->optimize_file($temp_file);
        
        @unlink($temp_file);
        
        return $optimized;
    }

    public function optimize(string $url): ?array
    {
        return $this->process_image($url);
    }

    private function download_image(string $url): ?array
    {
        $response = wp_remote_get($url, ['timeout' => 30]);
        
        if (is_wp_error($response)) {
            $this->logger->error("Failed to download image: " . $response->get_error_message());
            return null;
        }

        $content = wp_remote_retrieve_body($response);
        $type = wp_remote_retrieve_header($response, 'content-type');
        
        return [
            'content' => $content,
            'mime_type' => $type ?: 'image/jpeg',
        ];
    }

    private function download_to_temp(string $url): ?string
    {
        $filename = md5($url) . '.' . $this->get_extension_from_url($url);
        $filepath = $this->temp_dir . '/' . $filename;
        
        $response = wp_remote_get($url, [
            'timeout' => 30,
            'stream' => true,
            'filename' => $filepath,
        ]);
        
        if (is_wp_error($response)) {
            $this->logger->error("Failed to download image to temp: " . $response->get_error_message());
            return null;
        }

        return $filepath;
    }

    private function optimize_file(string $filepath): ?array
    {
        $quality = $this->config->get_image_quality();
        $create_webp = $this->config->is_webp_enabled();
        
        $image_info = @getimagesize($filepath);
        
        if (!$image_info) {
            return null;
        }

        $mime_type = $image_info['mime'];
        
        switch ($mime_type) {
            case 'image/jpeg':
                $image = @imagecreatefromjpeg($filepath);
                break;
            case 'image/png':
                $image = @imagecreatefrompng($filepath);
                break;
            case 'image/gif':
                $image = @imagecreatefromgif($filepath);
                break;
            default:
                return $this->download_image(wp_upload_dir()['baseurl'] . substr($filepath, strlen($this->temp_dir)));
        }

        if (!$image) {
            return null;
        }

        ob_start();
        
        if ($mime_type === 'image/png') {
            imagealphablending($image, false);
            imagesavealpha($image, true);
            imagepng($image, null, (int) (9 - ($quality / 10)));
        } else {
            imagejpeg($image, null, $quality);
        }
        
        $content = ob_get_clean();
        imagedestroy($image);

        if ($create_webp && function_exists('imagewebp')) {
            $webp_content = $this->convert_to_webp($filepath, $quality);
        }

        return [
            'content' => $content,
            'mime_type' => $mime_type,
            'webp' => $webp_content ?? null,
        ];
    }

    private function convert_to_webp(string $filepath, int $quality): ?string
    {
        $image_info = @getimagesize($filepath);
        
        if (!$image_info) {
            return null;
        }

        switch ($image_info['mime']) {
            case 'image/jpeg':
                $image = @imagecreatefromjpeg($filepath);
                break;
            case 'image/png':
                $image = @imagecreatefrompng($filepath);
                break;
            default:
                return null;
        }

        if (!$image) {
            return null;
        }

        ob_start();
        imagewebp($image, null, $quality);
        $content = ob_get_clean();
        imagedestroy($image);

        return $content;
    }

    private function get_extension_from_url(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        
        return in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp']) ? $ext : 'jpg';
    }

    public function cleanup_temp(): void
    {
        $files = glob($this->temp_dir . '/*');
        
        foreach ($files as $file) {
            if (is_file($file) && filemtime($file) < time() - 3600) {
                @unlink($file);
            }
        }
    }
}
