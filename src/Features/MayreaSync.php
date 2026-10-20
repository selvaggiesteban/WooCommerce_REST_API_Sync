<?php
/**
 * Mayrea Sync Feature
 *
 * Price, stock and SKU sync from the StProductos external API.
 * Only updates existing products; never creates or deletes them.
 * Scheduled four times daily (00:00, 06:00, 12:00, 18:00 site time)
 * plus manual runs via POST /wp-json/wc-api-sync/v1/sync/mayrea.
 *
 * @package WooCommerceApiSync\Features
 */

namespace WooCommerceApiSync\Features;

use WooCommerceApiSync\Core\Queue;
use WooCommerceApiSync\Utils\Logger;

defined('ABSPATH') || exit;

/**
 * Mayrea price/stock sync engine
 */
class MayreaSync
{
    /**
     * Queue job type handled by this feature
     */
    public const JOB_TYPE = 'mayrea_sync';

    /**
     * StProductos stock API endpoint
     */
    private const API_URL = 'https://aguilaapi.com/api/StProductos/sitio/dwaguila3g_mayrea/stock';

    /**
     * Action Scheduler hooks
     */
    private const HOOK_RUN = 'wc_api_sync_mayrea_run';
    private const HOOK_BATCH = 'wc_api_sync_mayrea_batch';
    private const GROUP = 'mayrea-sync';

    /**
     * Safety thresholds
     */
    private const MIN_RECORDS = 1000;
    private const BATCH_SIZE = 300;
    private const TIME_BUDGET = 15;
    private const SNAPSHOT_TTL = 3 * HOUR_IN_SECONDS;
    private const RUN_INTERVAL = 6 * HOUR_IN_SECONDS;

    /**
     * Options and transient keys
     */
    private const OPT_RUN = '_mayrea_sync_current';
    private const OPT_LAST = '_mayrea_sync_last_run';
    private const OPT_ERROR = '_mayrea_sync_last_error';
    private const OPT_LOCK = '_mayrea_sync_running';
    private const OPT_CHECK = '_mayrea_sync_sched_check';
    private const TRANSIENT = '_mayrea_sync_snapshot';

    private Queue $queue;
    private Logger $logger;

    /**
     * @param Queue $queue Plugin job queue
     */
    public function __construct(Queue $queue)
    {
        $this->queue = $queue;
        $this->logger = new Logger('mayrea');
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
    }

    /**
     * Enqueue a sync run through the plugin queue and wake the queue runner
     *
     * @param string $source Trigger source (rest|schedule)
     * @return int Job ID
     */
    public function enqueue_job(string $source = 'rest'): int
    {
        $job_id = $this->queue->add_job(self::JOB_TYPE, ['source' => $source]);

        if (function_exists('as_schedule_async_action')) {
            as_schedule_async_action('wc_api_sync_process_queue', [], self::GROUP);
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
        if ($this->is_running()) {
            $this->logger->info('Corrida programada omitida: ya hay una sincronización en curso.');
            return;
        }

        $this->enqueue_job('schedule');
    }

    /**
     * Self-heal: keep the recurring run scheduled (hourly throttle)
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
        if (as_has_scheduled_action(self::HOOK_RUN)) {
            return;
        }

        as_schedule_recurring_action(
            self::next_slot(),
            self::RUN_INTERVAL,
            self::HOOK_RUN,
            [],
            self::GROUP
        );

        $this->logger->info('Programación reparada: corrida recurrente reagendada.');
    }

    /**
     * Next run slot anchored to 00:00, 06:00, 12:00, 18:00
     *
     * @return int Unix timestamp
     */
    private static function next_slot(): int
    {
        $now = new \DateTimeImmutable('now', wp_timezone());
        $slots = [0, 6, 12, 18];

        foreach ($slots as $hour) {
            $candidate = $now->setTime($hour, 0, 0);
            if ($candidate > $now) {
                return $candidate->getTimestamp();
            }
        }

        return $now->modify('tomorrow')->setTime(0, 0, 0)->getTimestamp();
    }

    /**
     * Whether a run is in progress (1 hour lock)
     */
    private function is_running(): bool
    {
        $lock = (int) get_option(self::OPT_LOCK, 0);
        return (time() - $lock) < HOUR_IN_SECONDS;
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
     * Download the API payload, leave a snapshot and chain the first batch
     */
    private function run(): void
    {
        if ($this->is_running()) {
            $this->logger->info('Corrida omitida: ya hay una sincronización en curso.');
            return;
        }

        update_option(self::OPT_LOCK, time(), false);

        $endpoint = apply_filters('wc_api_sync_mayrea_api_url', self::API_URL);

        $response = wp_remote_get(
            $endpoint,
            [
                'timeout'    => 60,
                'blocking'   => true,
                'sslverify'  => false,
                'user-agent' => 'MayreaSync/' . WC_API_SYNC_VERSION,
                'headers'    => ['Accept' => 'application/json'],
            ]
        );

        if (is_wp_error($response)) {
            $this->set_error('No se pudo consultar la API: ' . $response->get_error_message());
            delete_option(self::OPT_LOCK);
            return;
        }

        if (200 !== (int) wp_remote_retrieve_response_code($response)) {
            $this->set_error('La API respondió HTTP ' . wp_remote_retrieve_response_code($response));
            delete_option(self::OPT_LOCK);
            return;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (!is_array($data) || count($data) < self::MIN_RECORDS) {
            $this->set_error(
                'Payload inválido o demasiado pequeño (' . (is_array($data) ? count($data) : 'no es array') . ' registros). Sincronización abortada para evitar dañar la tienda.'
            );
            delete_option(self::OPT_LOCK);
            return;
        }

        $items = [];
        $invalid = 0;

        foreach ($data as $row) {
            $code = isset($row['codigoStProducto']) ? trim((string) $row['codigoStProducto']) : '';

            if ('' === $code) {
                $invalid++;
                continue;
            }

            $price = isset($row['precioL4StConIva']) ? $row['precioL4StConIva'] : null;
            $stock = isset($row['wStock']) ? $row['wStock'] : null;

            if (!is_numeric($price) || !is_numeric($stock)) {
                $invalid++;
                continue;
            }

            $items[$code] = [
                'price' => round((float) $price, 2),
                'stock' => max(0, (int) $stock),
            ];
        }

        if (count($items) < self::MIN_RECORDS) {
            $this->set_error('Menos de ' . self::MIN_RECORDS . ' registros válidos tras normalizar. Sincronización abortada.');
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

        $this->logger->info('Corrida iniciada: ' . count($items) . ' registros en la API.');

        as_schedule_async_action(self::HOOK_BATCH, [0], self::GROUP);
    }

    /**
     * Build the SKU => product ID map from postmeta
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
            $this->set_error('Snapshot expirado; la corrida se interrumpe. Se retomará en la próxima programación.');
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
            'Corrida finalizada en ' . $stats['duration'] . 's: '
            . $stats['saved'] . ' productos guardados, '
            . $stats['price_updates'] . ' precios, '
            . $stats['stock_updates'] . ' stocks, '
            . $stats['unmatched'] . ' SKUs sin match, '
            . $stats['errors'] . ' errores.'
        );

        if (class_exists('LiteSpeed_Cache_API') && method_exists('LiteSpeed_Cache_API', 'purge_all')) {
            LiteSpeed_Cache_API::purge_all('mayrea_sync');
        }
    }
}
