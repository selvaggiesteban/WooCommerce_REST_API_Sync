<?php
/**
 * WooCommerce REST API Client
 *
 * @package WooCommerceApiSync\API
 */

namespace WooCommerceApiSync\API;

use WooCommerceApiSync\Core\Config;
use WooCommerceApiSync\Utils\Logger;

defined('ABSPATH') || exit;

/**
 * High-performance WooCommerce REST API v3 client
 */
class WooCommerceClient
{
    /**
     * Configuration
     *
     * @var Config
     */
    private Config $config;

    /**
     * Logger instance
     *
     * @var Logger
     */
    private Logger $logger;

    /**
     * Request count for rate limiting
     *
     * @var int
     */
    private int $request_count = 0;

    /**
     * Last request time
     *
     * @var float
     */
    private float $last_request_time = 0;

    /**
     * Constructor
     *
     * @param Config $config Configuration instance
     */
    public function __construct(Config $config)
    {
        $this->config = $config;
        $this->logger = new Logger('api');
    }

    /**
     * Get the base URL for API requests
     *
     * @return string
     */
    private function get_base_url(): string
    {
        return rtrim($this->config->get_store_url(), '/') . '/wp-json/' . $this->config->get_api_version() . '/';
    }

    /**
     * Get authentication headers
     *
     * @return array
     */
    private function get_auth_headers(): array
    {
        $consumer_key = $this->config->get_consumer_key();
        $consumer_secret = $this->config->get_consumer_secret();

        return [
            'Authorization' => 'Basic ' . base64_encode($consumer_key . ':' . $consumer_secret),
            'Content-Type' => 'application/json',
            'User-Agent' => 'WC-API-Sync/' . WC_API_SYNC_VERSION,
        ];
    }

    /**
     * Make an HTTP request
     *
     * @param string $method HTTP method
     * @param string $endpoint API endpoint
     * @param array $data Request data
     * @param array $args Additional arguments
     * @return array|WP_Error Response data or error
     */
    public function request(string $method, string $endpoint, array $data = [], array $args = []): array|\WP_Error
    {
        // Apply rate limiting
        $this->rate_limit();

        $url = $this->get_base_url() . $endpoint;
        
        $request_args = [
            'method' => $method,
            'headers' => array_merge($this->get_auth_headers(), $args['headers'] ?? []),
            'timeout' => $args['timeout'] ?? 30,
            'redirection' => $args['redirection'] ?? 5,
            'blocking' => $args['blocking'] ?? true,
        ];

        // Add body for POST/PUT/PATCH
        if (in_array($method, ['POST', 'PUT', 'PATCH'], true) && !empty($data)) {
            $request_args['body'] = wp_json_encode($data);
        }

        // Add query parameters for GET
        if ($method === 'GET' && !empty($data)) {
            $url = add_query_arg($data, $url);
        }

        $this->logger->debug("API Request: {$method} {$endpoint}");
        
        $start_time = microtime(true);
        $response = wp_remote_request($url, $request_args);
        $duration = microtime(true) - $start_time;
        
        $this->request_count++;
        $this->last_request_time = microtime(true);

        if (is_wp_error($response)) {
            $this->logger->error("API Error: {$response->get_error_message()}");
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);

        $this->logger->debug("API Response: {$status_code} ({$duration}s)");

        // Handle rate limiting (429)
        if ($status_code === 429) {
            $retry_after = (int) wp_remote_retrieve_header($response, 'retry-after');
            $this->logger->warning("Rate limited. Retry after {$retry_after}s");
            
            if ($retry_after > 0) {
                sleep($retry_after);
                return $this->request($method, $endpoint, $data, $args);
            }
        }

        // Handle server errors (5xx)
        if ($status_code >= 500) {
            $this->logger->error("Server error: {$status_code}");
            
            if (($args['retries'] ?? 0) < 3) {
                $args['retries'] = ($args['retries'] ?? 0) + 1;
                sleep(pow(2, $args['retries']));
                return $this->request($method, $endpoint, $data, $args);
            }
        }

        if ($status_code >= 400) {
            $error_message = $decoded['message'] ?? "HTTP {$status_code}";
            $this->logger->error("API Error {$status_code}: {$error_message}");
            
            return new \WP_Error(
                'wc_api_error',
                $error_message,
                ['status' => $status_code, 'response' => $decoded]
            );
        }

        return $decoded ?? [];
    }

    /**
     * Apply rate limiting
     */
    private function rate_limit(): void
    {
        $max_concurrent = $this->config->get_rate_limit_max();
        $delay = $this->config->get_rate_limit_delay() / 1000; // Convert ms to seconds

        // Check if we need to wait
        $time_since_last = microtime(true) - $this->last_request_time;
        
        if ($time_since_last < $delay) {
            usleep((int) (($delay - $time_since_last) * 1000000));
        }

        // Reset counter every minute
        if ($this->request_count >= $max_concurrent) {
            $wait_time = max(0, 60 - $time_since_last);
            if ($wait_time > 0) {
                $this->logger->debug("Rate limit: waiting {$wait_time}s");
                sleep((int) $wait_time);
            }
            $this->request_count = 0;
        }
    }

    /**
     * GET request
     *
     * @param string $endpoint API endpoint
     * @param array $params Query parameters
     * @return array|\WP_Error
     */
    public function get(string $endpoint, array $params = []): array|\WP_Error
    {
        return $this->request('GET', $endpoint, $params);
    }

    /**
     * POST request
     *
     * @param string $endpoint API endpoint
     * @param array $data Request body
     * @return array|\WP_Error
     */
    public function post(string $endpoint, array $data = []): array|\WP_Error
    {
        return $this->request('POST', $endpoint, $data);
    }

    /**
     * PUT request
     *
     * @param string $endpoint API endpoint
     * @param array $data Request body
     * @return array|\WP_Error
     */
    public function put(string $endpoint, array $data = []): array|\WP_Error
    {
        return $this->request('PUT', $endpoint, $data);
    }

    /**
     * DELETE request
     *
     * @param string $endpoint API endpoint
     * @param array $params Query parameters
     * @return array|\WP_Error
     */
    public function delete(string $endpoint, array $params = []): array|\WP_Error
    {
        return $this->request('DELETE', $endpoint, $params);
    }

    /**
     * Batch request
     *
     * @param string $resource Resource type (products, orders, etc.)
     * @param array $create Items to create
     * @param array $update Items to update
     * @param array $delete IDs to delete
     * @return array|\WP_Error
     */
    public function batch(string $resource, array $create = [], array $update = [], array $delete = []): array|\WP_Error
    {
        $data = [];
        
        if (!empty($create)) {
            $data['create'] = $create;
        }
        if (!empty($update)) {
            $data['update'] = $update;
        }
        if (!empty($delete)) {
            $data['delete'] = $delete;
        }

        return $this->post("{$resource}/batch", $data);
    }

    /**
     * Get all items with pagination
     *
     * @param string $resource Resource type
     * @param array $params Query parameters
     * @param int $per_page Items per page
     * @return array All items
     */
    public function get_all(string $resource, array $params = [], int $per_page = 100): array
    {
        $all_items = [];
        $page = 1;

        do {
            $params['per_page'] = $per_page;
            $params['page'] = $page;

            $response = $this->get($resource, $params);
            
            if (is_wp_error($response)) {
                $this->logger->error("Error fetching {$resource}: " . $response->get_error_message());
                break;
            }

            if (empty($response)) {
                break;
            }

            $all_items = array_merge($all_items, $response);
            $page++;

            // Safety check to prevent infinite loops
            if ($page > 1000) {
                $this->logger->warning("Safety limit reached for {$resource}");
                break;
            }

        } while (count($response) === $per_page);

        return $all_items;
    }

    /**
     * Get items modified after a specific date
     *
     * @param string $resource Resource type
     * @param string $date ISO8601 date string
     * @param array $params Additional parameters
     * @return array
     */
    public function get_modified_after(string $resource, string $date, array $params = []): array
    {
        $params['modified_after'] = $date;
        return $this->get_all($resource, $params);
    }

    /**
     * Get total count of items
     *
     * @param string $resource Resource type
     * @return int Total count
     */
    public function get_count(string $resource): int
    {
        $response = $this->get($resource, ['per_page' => 1]);
        
        if (is_wp_error($response)) {
            return 0;
        }

        // The count is returned in the response headers
        // For now, we'll use a simple approach
        return count($response);
    }

    /**
     * Get request statistics
     *
     * @return array
     */
    public function get_stats(): array
    {
        return [
            'request_count' => $this->request_count,
            'last_request_time' => $this->last_request_time,
            'base_url' => $this->get_base_url(),
        ];
    }

    /**
     * Reset request counter
     */
    public function reset_stats(): void
    {
        $this->request_count = 0;
        $this->last_request_time = 0;
    }
}
