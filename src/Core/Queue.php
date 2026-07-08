<?php
/**
 * Job Queue
 *
 * @package WooCommerceApiSync\Core
 */

namespace WooCommerceApiSync\Core;

defined('ABSPATH') || exit;

/**
 * Queue manager for async job processing
 */
class Queue
{
    /**
     * Table name
     */
    private string $table_name;

    /**
     * Configuration
     *
     * @var Config
     */
    private Config $config;

    /**
     * Constructor
     *
     * @param Config $config Configuration instance
     */
    public function __construct(Config $config)
    {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'wc_sync_queue';
        $this->config = $config;
    }

    /**
     * Add a job to the queue
     *
     * @param string $job_type Job type identifier
     * @param array $payload Job data
     * @param int $priority Job priority (lower = higher priority)
     * @return int Job ID
     */
    public function add_job(string $job_type, array $payload, int $priority = 0): int
    {
        global $wpdb;

        $wpdb->insert(
            $this->table_name,
            [
                'job_type' => $job_type,
                'payload' => wp_json_encode($payload),
                'priority' => $priority,
                'status' => 'pending',
            ],
            ['%s', '%s', '%d', '%s']
        );

        return (int) $wpdb->insert_id;
    }

    /**
     * Get next pending job
     *
     * @return object|null Job object or null
     */
    public function get_next_job(): ?object
    {
        global $wpdb;

        $job = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_name} 
                WHERE status = 'pending' 
                AND attempts < max_attempts 
                ORDER BY priority ASC, created_at ASC 
                LIMIT 1"
            )
        );

        if ($job) {
            // Mark as processing
            $wpdb->update(
                $this->table_name,
                [
                    'status' => 'processing',
                    'attempts' => $job->attempts + 1,
                ],
                ['id' => $job->id],
                ['%s', '%d']
            );
        }

        return $job;
    }

    /**
     * Mark job as completed
     *
     * @param int $job_id Job ID
     */
    public function complete_job(int $job_id): void
    {
        global $wpdb;

        $wpdb->update(
            $this->table_name,
            [
                'status' => 'completed',
                'processed_at' => current_time('mysql'),
            ],
            ['id' => $job_id],
            ['%s', '%s']
        );
    }

    /**
     * Mark job as failed
     *
     * @param int $job_id Job ID
     * @param string $error_message Error message
     */
    public function fail_job(int $job_id, string $error_message = ''): void
    {
        global $wpdb;

        $wpdb->update(
            $this->table_name,
            [
                'status' => 'failed',
                'processed_at' => current_time('mysql'),
                'error_message' => $error_message,
            ],
            ['id' => $job_id],
            ['%s', '%s', '%s']
        );
    }

    /**
     * Process pending jobs
     *
     * @param int $max_jobs Maximum number of jobs to process
     * @return int Number of jobs processed
     */
    public function process(int $max_jobs = 10): int
    {
        $processed = 0;

        while ($processed < $max_jobs) {
            $job = $this->get_next_job();
            
            if (!$job) {
                break;
            }

            try {
                $this->execute_job($job);
                $this->complete_job($job->id);
            } catch (\Exception $e) {
                $this->fail_job($job->id, $e->getMessage());
            }

            $processed++;
        }

        return $processed;
    }

    /**
     * Execute a job
     *
     * @param object $job Job object
     */
    private function execute_job(object $job): void
    {
        $payload = json_decode($job->payload, true);

        switch ($job->job_type) {
            case 'full_sync':
                $this->execute_full_sync($payload);
                break;
            
            case 'incremental_sync':
                $this->execute_incremental_sync($payload);
                break;
            
            case 'batch_sync':
                $this->execute_batch_sync($payload);
                break;
            
            case 'webhook_process':
                $this->execute_webhook_process($payload);
                break;
            
            default:
                do_action('wc_api_sync_execute_job', $job->job_type, $payload);
                break;
        }
    }

    /**
     * Execute full sync job
     *
     * @param array $payload Job payload
     */
    private function execute_full_sync(array $payload): void
    {
        $domain = $payload['domain'] ?? null;
        
        if (!$domain) {
            throw new \Exception('Domain not specified for full sync');
        }

        do_action("wc_api_sync_full_sync_{$domain}", $payload);
    }

    /**
     * Execute incremental sync job
     *
     * @param array $payload Job payload
     */
    private function execute_incremental_sync(array $payload): void
    {
        do_action('wc_api_sync_incremental_sync', $payload);
    }

    /**
     * Execute batch sync job
     *
     * @param array $payload Job payload
     */
    private function execute_batch_sync(array $payload): void
    {
        $domain = $payload['domain'] ?? null;
        $items = $payload['items'] ?? [];
        $action = $payload['action'] ?? 'update'; // create|update|delete

        if (!$domain || empty($items)) {
            throw new \Exception('Invalid batch sync payload');
        }

        do_action("wc_api_sync_batch_{$action}_{$domain}", $items, $payload);
    }

    /**
     * Execute webhook processing job
     *
     * @param array $payload Job payload
     */
    private function execute_webhook_process(array $payload): void
    {
        $topic = $payload['topic'] ?? null;
        $data = $payload['data'] ?? [];

        if (!$topic) {
            throw new \Exception('Webhook topic not specified');
        }

        do_action("wc_api_sync_webhook_{$topic}", $data, $payload);
    }

    /**
     * Get queue statistics
     *
     * @return array
     */
    public function get_stats(): array
    {
        global $wpdb;

        $stats = $wpdb->get_row(
            "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
            FROM {$this->table_name}"
        );

        return [
            'total' => (int) ($stats->total ?? 0),
            'pending' => (int) ($stats->pending ?? 0),
            'processing' => (int) ($stats->processing ?? 0),
            'completed' => (int) ($stats->completed ?? 0),
            'failed' => (int) ($stats->failed ?? 0),
        ];
    }

    /**
     * Clear completed jobs older than specified days
     *
     * @param int $days Number of days
     * @return int Number of deleted jobs
     */
    public function clear_old_jobs(int $days = 7): int
    {
        global $wpdb;

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$this->table_name} 
                WHERE status IN ('completed', 'failed') 
                AND processed_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days
            )
        );

        return $wpdb->rows_affected;
    }

    /**
     * Retry failed jobs
     *
     * @param int $max_attempts Maximum retry attempts
     * @return int Number of jobs reset
     */
    public function retry_failed(int $max_attempts = 3): int
    {
        global $wpdb;

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->table_name} 
                SET status = 'pending', attempts = 0 
                WHERE status = 'failed' 
                AND attempts >= %d",
                $max_attempts
            )
        );

        return $wpdb->rows_affected;
    }
}
