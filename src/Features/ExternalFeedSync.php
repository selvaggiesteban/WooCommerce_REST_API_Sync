<?php
/**
 * External Feed Sync Feature
 *
 * Generic price/stock sync from an external JSON feed. The feed URL,
 * field mapping and frequency are site configuration (WC API Sync >
 * Settings, or the wc_api_sync_external_feed_config filter); without
 * configuration the feature stays idle.
 *
 * Runs are dispatched through the plugin queue (job type external_feed)
 * and batched via Action Scheduler with a time budget.
 *
 * @package WooCommerceApiSync\Features
 */

namespace WooCommerceApiSync\Features;

use WooCommerceApiSync\Core\Config;
use WooCommerceApiSync\Core\Queue;
use WooCommerceApiSync\Utils\Logger;

defined('ABSPATH') || exit;

/**
 * External feed price/stock sync engine
 */
class ExternalFeedSync
{
    /**
     * Queue job type handled by this feature
     */
    public const JOB_TYPE = 'external_feed';

    /**
     * Supported scheduling frequencies (settings select values)
     */
    public const FREQUENCIES = ['daily', 'twicedaily', 'fourtimesdaily', 'sixhourly', 'twelvehourly'];

    /**
     * Action Scheduler hooks
     */
    private const HOOK_RUN = 'wc_api_sync_external_feed_run';
    private const HOOK_BATCH = 'wc_api_sync_external_feed_batch';
    private const GROUP = 'wc-external-feed';

    /**
     * Safety thresholds
     */
    private const BATCH_SIZE = 300;
    private const TIME_BUDGET = 15;
    private const SNAPSHOT_TTL = 3 * HOUR_IN_SECONDS;
    private const DEFAULT_MIN_RECORDS = 1000;

    /**
     * Options and transient keys
     */
    private const OPT_RUN = '_wc_ext_feed_current';
    private const OPT_LAST = '_wc_ext_feed_last_run';
    private const OPT_ERROR = '_wc_ext_feed_last_error';
    private const OPT_LOCK = '_wc_ext_feed_running';
    private const OPT_CHECK = '_wc_ext_feed_sched_check';
    private const OPT_SCHED_FREQ = '_wc_ext_feed_sched_freq';
    private const TRANSIENT = '_wc_ext_feed_snapshot';

    private Config $config;
    private Queue $queue;
    private Logger $logger;

    /**
     * @param Config $config Plugin configuration
     * @param Queue  $queue  Plugin job queue
     */
    public function __construct(Config $config, Queue $queue)
    {
        $this->config = $config;
        $this->queue = $queue;
        $this->logger = new Logger('feed');
    }

    /**
     * Register hooks
     */
    public function init(): void
    {
        add_action('wc_api_sync_execute_job', [$this, 'handle_job'], 10, 2);
        add_action(self::HOOK_RUN, [$this, 'enqueue_run']);
        add_action(self::HOOK_BATCH, [$this, 'batch'], 10, 1);
        add_action('init', [$this, 'maybe_schedule'], 20);

        add_action('update_option_wc_api_sync_settings', function () {
            delete_option(self::OPT_CHECK);
        });
    }

    /**
     * Effective feed configuration (settings + filter), normalized
     */
    public function get_config(): array
    {
        $config = apply_filters('wc_api_sync_external_feed_config', [
            'url' => $this->config->get('feed_url', ''),
            'sku' => $this->config->get('feed_sku', ''),
            'price' => $this->config->get('feed_price', ''),
            'stock' => $this->config->get('feed_stock', ''),
            'frequency' => $this->config->get('feed_frequency', ''),
            'min_records' => $this->config->get('feed_min_records', self::DEFAULT_MIN_RECORDS),
        ]);

        return [
            'url' => trim((string) ($config['url'] ?? '')),
            'sku' => trim((string) ($config['sku'] ?? '')),
            'price' => trim((string) ($config['price'] ?? '')),
            'stock' => trim((string) ($config['stock'] ?? '')),
            'frequency' => in_array(($config['frequency'] ?? ''), self::FREQUENCIES, true) ? $config['frequency'] : '',
            'min_records' => max(1, (int) ($config['min_records'] ?? self::DEFAULT_MIN_RECORDS)),
        ];
    }

    /**
     * Whether URL and field mapping are set (required to run)
     */
    public function has_payload_config(): bool
    {
        $cfg = $this->get_config();
        return '' !== $cfg['url'] && '' !== $cfg['sku'] && '' !== $cfg['price'] && '' !== $cfg['stock'];
    }

    /**
     * Whether the feed is fully configured including frequency
     */
    public function is_configured(): bool
    {
        $cfg = $this->get_config();
        return $this->has_payload_config() && '' !== $cfg['frequency'];
    }

    /**
     * Queue a sync run and wake the queue runner
     *
     * @param string $source Trigger source (rest|manual|schedule)
     * @return int Job ID
     */
    public function enqueue_job(string $source = 'manual'): int
    {
        $job_id = $this->queue->add_job(self::JOB_TYPE, ['source' => $source]);

        if (function_exists('as_schedule_async_action')) {
            as_schedule_async_action('wc_api_sync_process_queue');
        }

        return $job_id;
    }

    /**
     * Queue hook: dispatch job types owned by this feature
     *
     * @param string $job_type Job type
     * @param array  $payload  Job payload
     */
    public function handle_job(string $job_type, array $payload = []): void
    {
        if (self::JOB_TYPE === $job_type) {
            $this->run();
        }
    }

    /**
     * Recurring trigger: enqueue the next scheduled run
     */
    public function enqueue_run(): void
    {
        if (!$this->has_payload_config()) {
            return;
        }

        if ($this->is_running()) {
            $this->logger->info('Scheduled feed run skipped: a sync is already in progress.');
            return;
        }

        $this->enqueue_job('schedule');
    }

    /**
     * Self-heal: keep the recurring run in sync with the configured
     * frequency (hourly throttle, forced again on settings changes)
     */
    public function maybe_schedule(): void
    {
        $last = (int) get_option(self::OPT_CHECK, 0);
        if ((time() - $last) < HOUR_IN_SECONDS) {
            return;
        }
        update_option(self::OPT_CHECK, time(), false);

        if (!function_exists('as_schedule_recurring_action')) {
            return;
        }

        $frequency = $this->get_config()['frequency'];

        if (!$this->is_configured()) {
            if (function_exists('as_unschedule_action') && as_has_scheduled_action(self::HOOK_RUN)) {
                as_unschedule_action(self::HOOK_RUN);
            }
            delete_option(self::OPT_SCHED_FREQ);
            return;
        }

        $scheduled_frequency = (string) get_option(self::OPT_SCHED_FREQ, '');
        if (as_has_scheduled_action(self::HOOK_RUN) && $scheduled_frequency === $frequency) {
            return;
        }

        if (as_has_scheduled_action(self::HOOK_RUN)) {
            as_unschedule_action(self::HOOK_RUN);
        }

        [$next, $interval] = self::schedule_params($frequency);
        if ($next <= 0 || $interval <= 0) {
            return;
        }

        as_schedule_recurring_action($next, $interval, self::HOOK_RUN, [], self::GROUP);
        update_option(self::OPT_SCHED_FREQ, $frequency, false);
        $this->logger->info('Feed schedule updated: ' . $frequency);
    }

    /**
     * First run timestamp and interval for a frequency
     *
     * @param string $frequency Frequency value
     * @return array{0: int, 1: int} [next timestamp, interval seconds]
     */
    private static function schedule_params(string $frequency): array
    {
        if ('sixhourly' === $frequency) {
            return [time() + 6 * HOUR_IN_SECONDS, 6 * HOUR_IN_SECONDS];
        }
        if ('twelvehourly' === $frequency) {
            return [time() + 12 * HOUR_IN_SECONDS, 12 * HOUR_IN_SECONDS];
        }

        if ('daily' === $frequency) {
            $slots = [0];
            $interval = DAY_IN_SECONDS;
        } elseif ('twicedaily' === $frequency) {
            $slots = [0, 12];
            $interval = 12 * HOUR_IN_SECONDS;
        } else {
            $slots = [0, 6, 12, 18];
            $interval = 6 * HOUR_IN_SECONDS;
        }

        $now = new \DateTimeImmutable('now', wp_timezone());

        foreach ($slots as $hour) {
            $candidate = $now->setTime($hour, 0, 0);
            if ($candidate > $now) {
                return [$candidate->getTimestamp(), $interval];
            }
        }

        return [$now->modify('tomorrow')->setTime(0, 0, 0)->getTimestamp(), $interval];
    }

    /**
     * Whether a run is in progress (1 hour lock)
     */
    public function is_running(): bool
    {
        $lock = (int) get_option(self::OPT_LOCK, 0);
        return (time() - $lock) < HOUR_IN_SECONDS;
    }

    /**
     * Next scheduled run timestamp
     *
     * @return int|false Unix timestamp or false when not scheduled
     */
    public function get_next_run()
    {
        return function_exists('as_next_scheduled_action')
            ? as_next_scheduled_action(self::HOOK_RUN)
            : false;
    }

    /**
     * Stats of the last finished run
     */
    public function get_last_run(): ?array
    {
        $last = get_option(self::OPT_LAST, null);
        return is_array($last) ? $last : null;
    }

    /**
     * Last stored error
     */
    public function get_current_error(): ?array
    {
        $error = get_option(self::OPT_ERROR, null);
        return is_array($error) ? $error : null;
    }

    /**
     * Store and log a fatal error for the current run
     *
     * @param string $message Error message
     */
    private function set_error(string $message): void
    {
        update_option(self::OPT_ERROR, ['time' => time(), 'message' => $message], false);
        $this->logger->error($message);
    }

    /**
     * Download the feed payload, leave a snapshot and chain the first batch
     */
    private function run(): void
    {
        if ($this->is_running()) {
            $this->logger->info('Feed run skipped: a sync is already in progress.');
            return;
        }

        if (!$this->has_payload_config()) {
            $this->set_error('External feed is not configured (URL and field mapping required).');
            return;
        }

        $cfg = $this->get_config();

        update_option(self::OPT_LOCK, time(), false);

        $response = wp_remote_get(
            $cfg['url'],
            [
                'timeout'    => 60,
                'blocking'   => true,
                'user-agent' => 'WC-API-Sync-Feed/' . WC_API_SYNC_VERSION,
                'headers'    => ['Accept' => 'application/json'],
            ]
        );

        if (is_wp_error($response)) {
            $this->set_error('Feed request failed: ' . $response->get_error_message());
            delete_option(self::OPT_LOCK);
            return;
        }

        if (200 !== (int) wp_remote_retrieve_response_code($response)) {
            $this->set_error('Feed responded HTTP ' . wp_remote_retrieve_response_code($response));
            delete_option(self::OPT_LOCK);
            return;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (!is_array($data) || count($data) < $cfg['min_records']) {
            $this->set_error(
                'Invalid or too small payload (' . (is_array($data) ? count($data) : 'not an array') . ' records). Sync aborted to protect the store.'
            );
            delete_option(self::OPT_LOCK);
            return;
        }

        $items = [];
        $invalid = 0;

        foreach ($data as $row) {
            $code = isset($row[$cfg['sku']]) ? trim((string) $row[$cfg['sku']]) : '';

            if ('' === $code) {
                $invalid++;
                continue;
            }

            $price = $row[$cfg['price']] ?? null;
            $stock = $row[$cfg['stock']] ?? null;

            if (!is_numeric($price) || !is_numeric($stock)) {
                $invalid++;
                continue;
            }

            $items[$code] = [
                'price' => round((float) $price, 2),
                'stock' => max(0, (int) $stock),
            ];
        }

        if (count($items) < $cfg['min_records']) {
            $this->set_error('Fewer than ' . $cfg['min_records'] . ' valid records after normalization. Sync aborted.');
            delete_option(self::OPT_LOCK);
            return;
        }

        set_transient(self::TRANSIENT, $items, self::SNAPSHOT_TTL);

        update_option(
            self::OPT_RUN,
            [
                'started'          => time(),
                'api_total'        => count($items),
                'api_invalid'      => $invalid,
                'offset'           => 0,
                'processed'        => 0,
                'saved'            => 0,
                'price_updates'    => 0,
                'stock_updates'    => 0,
                'status_updates'   => 0,
                'unchanged'        => 0,
                'unmatched'        => 0,
                'unmatched_sample' => [],
                'errors'           => 0,
                'batches'          => 0,
            ],
            false
        );

        $this->logger->info('Feed run started: ' . count($items) . ' records.');

        as_schedule_async_action(self::HOOK_BATCH, [0], self::GROUP);
    }

    /**
     * Build the value => product ID map for the configured SKU field
     *
     * @return array<string, int[]>
     */
    private function sku_map(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT p.ID AS id, pm.meta_value AS sku
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
             WHERE pm.meta_key = '_sku'
               AND p.post_type = 'product'
               AND p.post_status <> 'trash'",
            ARRAY_A
        );

        $map = [];

        foreach ((array) $rows as $row) {
            $key = trim((string) $row['sku']);
            if ('' === $key) {
                continue;
            }
            $map[$key][] = (int) $row['id'];
        }

        return $map;
    }

    /**
     * Process one chunk of the snapshot within the time budget
     *
     * @param int $offset Snapshot offset
     */
    public function batch(int $offset = 0): void
    {
        $stats = get_option(self::OPT_RUN);

        if (!is_array($stats)) {
            delete_transient(self::TRANSIENT);
            delete_option(self::OPT_LOCK);
            return;
        }

        $items = get_transient(self::TRANSIENT);

        if (!is_array($items) || empty($items)) {
            $this->set_error('Snapshot expired; the run is interrupted. It will resume on the next schedule.');
            delete_option(self::OPT_LOCK);
            return;
        }

        $offset = max(0, (int) $offset);
        $chunk = array_slice($items, $offset, self::BATCH_SIZE, true);
        $sku_map = $this->sku_map();
        $started = microtime(true);
        $processed = 0;
        $n = 0;

        foreach ($chunk as $code => $item) {
            $code = (string) $code;
            $ids = $sku_map[$code] ?? [];
            $processed++;
            $n++;

            if (empty($ids)) {
                $stats['unmatched']++;
                if (count($stats['unmatched_sample']) < 30 && !in_array($code, $stats['unmatched_sample'], true)) {
                    $stats['unmatched_sample'][] = $code;
                }
                continue;
            }

            foreach ($ids as $product_id) {
                try {
                    $product = wc_get_product($product_id);

                    if (!$product) {
                        $stats['errors']++;
                        continue;
                    }

                    $changed = false;

                    $price = $item['price'];

                    if (abs((float) $product->get_regular_price() - $price) > 0.005) {
                        $product->set_regular_price($price);
                        $changed = true;
                        $stats['price_updates']++;
                    }

                    $sale = $product->get_sale_price();
                    $on_sale = is_numeric($sale) && (float) $sale > 0;

                    if (!$on_sale && abs((float) $product->get_price() - $price) > 0.005) {
                        $product->set_price($price);
                        $changed = true;
                    }

                    $stock = $item['stock'];
                    $current_stock = $product->get_stock_quantity();

                    if (null === $current_stock || abs((float) $current_stock - $stock) > 0.005) {
                        $product->set_stock_quantity($stock);
                        $changed = true;
                        $stats['stock_updates']++;
                    }

                    $status = $stock > 0 ? 'instock' : 'outofstock';

                    if ($product->get_stock_status() !== $status) {
                        $product->set_stock_status($status);
                        $changed = true;
                        $stats['status_updates']++;
                    }

                    if (trim((string) $product->get_sku()) !== $code) {
                        $product->set_sku($code);
                        $changed = true;
                    }

                    if ($changed) {
                        $product->save();
                        $stats['saved']++;
                    } else {
                        $stats['unchanged']++;
                    }
                } catch (\Throwable $e) {
                    $stats['errors']++;
                }
            }

            if (0 === ($n % 5) && (microtime(true) - $started) > self::TIME_BUDGET) {
                break;
            }
        }

        $offset += $processed;
        $stats['offset'] = $offset;
        $stats['processed'] = $stats['processed'] + $processed;
        $stats['batches']++;

        update_option(self::OPT_RUN, $stats, false);

        if ($offset < count($items)) {
            as_schedule_async_action(self::HOOK_BATCH, [$offset], self::GROUP);
            return;
        }

        $this->finalize($stats);
    }

    /**
     * Persist the final stats and release the run lock
     *
     * @param array $stats Run statistics
     */
    private function finalize(array $stats): void
    {
        $stats['finished'] = time();
        $stats['duration'] = $stats['finished'] - $stats['started'];

        update_option(self::OPT_LAST, $stats, false);
        delete_option(self::OPT_RUN);
        delete_transient(self::TRANSIENT);
        delete_option(self::OPT_LOCK);

        $this->logger->info(
            'Feed run finished in ' . $stats['duration'] . 's: '
            . $stats['saved'] . ' products saved, '
            . $stats['price_updates'] . ' prices, '
            . $stats['stock_updates'] . ' stock, '
            . $stats['unmatched'] . ' unmatched, '
            . $stats['errors'] . ' errors.'
        );

        if (class_exists('LiteSpeed_Cache_API') && method_exists('LiteSpeed_Cache_API', 'purge_all')) {
            LiteSpeed_Cache_API::purge_all('wc_api_sync_feed');
        }
    }
}
