<?php
/**
 * Admin Settings Page
 *
 * @package WooCommerceApiSync
 */

defined('ABSPATH') || exit;

use WooCommerceApiSync\Core\Config;
use WooCommerceApiSync\Features\ExternalFeedSync;

$config = new Config();
$settings = $config->all();

$feed_payload_ready = trim((string) ($settings['feed_url'] ?? '')) !== ''
    && trim((string) ($settings['feed_sku'] ?? '')) !== ''
    && trim((string) ($settings['feed_price'] ?? '')) !== ''
    && trim((string) ($settings['feed_stock'] ?? '')) !== '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'wc_api_sync_save') {
    check_admin_referer('wc_api_sync_settings');
    
    $new_settings = [
        'store_url' => sanitize_url($_POST['store_url'] ?? ''),
        'consumer_key' => sanitize_text_field($_POST['consumer_key'] ?? ''),
        'consumer_secret' => sanitize_text_field($_POST['consumer_secret'] ?? ''),
        'sync_mode' => sanitize_text_field($_POST['sync_mode'] ?? 'bidirectional'),
        'rate_limit_max' => absint($_POST['rate_limit_max'] ?? 5),
        'rate_limit_delay' => absint($_POST['rate_limit_delay'] ?? 100),
        'batch_size' => absint($_POST['batch_size'] ?? 100),
        'image_optimization' => !empty($_POST['image_optimization']),
        'image_webp' => !empty($_POST['image_webp']),
        'image_quality' => absint($_POST['image_quality'] ?? 80),
        'log_level' => sanitize_text_field($_POST['log_level'] ?? 'info'),
        'debug_mode' => !empty($_POST['debug_mode']),
        'feed_url' => esc_url_raw($_POST['feed_url'] ?? ''),
        'feed_sku' => sanitize_text_field($_POST['feed_sku'] ?? ''),
        'feed_price' => sanitize_text_field($_POST['feed_price'] ?? ''),
        'feed_stock' => sanitize_text_field($_POST['feed_stock'] ?? ''),
        'feed_frequency' => in_array($_POST['feed_frequency'] ?? '', ExternalFeedSync::FREQUENCIES, true) ? sanitize_text_field($_POST['feed_frequency']) : '',
        'feed_min_records' => absint($_POST['feed_min_records'] ?? 1000),
        'full_sync_interval' => absint($_POST['full_sync_interval'] ?? 0),
        'incremental_sync_interval' => absint($_POST['incremental_sync_interval'] ?? 0),
    ];
    
    foreach ($new_settings as $key => $value) {
        $config->set($key, $value);
    }
    $config->save();
    
    echo '<div class="notice notice-success"><p>' . esc_html__('Settings saved.', 'wc-api-sync') . '</p></div>';
}
?>

<div class="wrap">
    <h1><?php esc_html_e('WooCommerce API Sync Settings', 'wc-api-sync'); ?></h1>

    <?php
    $feed_notice = sanitize_text_field(wp_unslash($_GET['wc-api-sync-feed'] ?? ''));
    if ('queued' === $feed_notice) {
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('External feed sync queued. It will run in a few seconds.', 'wc-api-sync') . '</p></div>';
    } elseif ('not-configured' === $feed_notice) {
        echo '<div class="notice notice-error"><p>' . esc_html__('External feed is not configured: set the URL and field mapping first.', 'wc-api-sync') . '</p></div>';
    } elseif ('running' === $feed_notice) {
        echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__('An external feed sync is already in progress.', 'wc-api-sync') . '</p></div>';
    }
    ?>
    
    <form method="post" action="">
        <?php wp_nonce_field('wc_api_sync_settings'); ?>
        <input type="hidden" name="action" value="wc_api_sync_save" />
        
        <h2><?php esc_html_e('API Configuration', 'wc-api-sync'); ?></h2>
        <table class="form-table">
            <tr>
                <th scope="row"><label for="store_url"><?php esc_html_e('Store URL', 'wc-api-sync'); ?></label></th>
                <td>
                    <input type="url" id="store_url" name="store_url" value="<?php echo esc_attr($settings['store_url'] ?? ''); ?>" class="regular-text" />
                    <p class="description"><?php esc_html_e('Your WooCommerce store URL.', 'wc-api-sync'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="consumer_key"><?php esc_html_e('Consumer Key', 'wc-api-sync'); ?></label></th>
                <td>
                    <input type="text" id="consumer_key" name="consumer_key" value="<?php echo esc_attr($settings['consumer_key'] ?? ''); ?>" class="regular-text" />
                    <p class="description"><?php esc_html_e('WooCommerce REST API consumer key.', 'wc-api-sync'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="consumer_secret"><?php esc_html_e('Consumer Secret', 'wc-api-sync'); ?></label></th>
                <td>
                    <input type="password" id="consumer_secret" name="consumer_secret" value="<?php echo esc_attr($settings['consumer_secret'] ?? ''); ?>" class="regular-text" />
                    <p class="description"><?php esc_html_e('WooCommerce REST API consumer secret.', 'wc-api-sync'); ?></p>
                </td>
            </tr>
        </table>
        
        <h2><?php esc_html_e('Sync Settings', 'wc-api-sync'); ?></h2>
        <table class="form-table">
            <tr>
                <th scope="row"><label for="sync_mode"><?php esc_html_e('Sync Mode', 'wc-api-sync'); ?></label></th>
                <td>
                    <select id="sync_mode" name="sync_mode">
                        <option value="bidirectional" <?php selected($settings['sync_mode'] ?? '', 'bidirectional'); ?>><?php esc_html_e('Bidirectional', 'wc-api-sync'); ?></option>
                        <option value="push" <?php selected($settings['sync_mode'] ?? '', 'push'); ?>><?php esc_html_e('Push Only', 'wc-api-sync'); ?></option>
                        <option value="pull" <?php selected($settings['sync_mode'] ?? '', 'pull'); ?>><?php esc_html_e('Pull Only', 'wc-api-sync'); ?></option>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="batch_size"><?php esc_html_e('Batch Size', 'wc-api-sync'); ?></label></th>
                <td>
                    <input type="number" id="batch_size" name="batch_size" value="<?php echo esc_attr($settings['batch_size'] ?? 100); ?>" min="1" max="100" />
                    <p class="description"><?php esc_html_e('Number of items per batch request (max 100).', 'wc-api-sync'); ?></p>
                </td>
            </tr>
        </table>
        
        <h2><?php esc_html_e('Programmatic Sync', 'wc-api-sync'); ?></h2>
        <table class="form-table">
            <tr>
                <th scope="row"><label for="full_sync_interval"><?php esc_html_e('Full Sync Every', 'wc-api-sync'); ?></label></th>
                <td>
                    <select id="full_sync_interval" name="full_sync_interval">
                        <option value="0" <?php selected((int) ($settings['full_sync_interval'] ?? 0), 0); ?>><?php esc_html_e('Disabled', 'wc-api-sync'); ?></option>
                        <option value="1" <?php selected((int) ($settings['full_sync_interval'] ?? 0), 1); ?>><?php esc_html_e('Hourly', 'wc-api-sync'); ?></option>
                        <option value="3" <?php selected((int) ($settings['full_sync_interval'] ?? 0), 3); ?>><?php esc_html_e('Every 3 hours', 'wc-api-sync'); ?></option>
                        <option value="6" <?php selected((int) ($settings['full_sync_interval'] ?? 0), 6); ?>><?php esc_html_e('Every 6 hours', 'wc-api-sync'); ?></option>
                        <option value="12" <?php selected((int) ($settings['full_sync_interval'] ?? 0), 12); ?>><?php esc_html_e('Every 12 hours', 'wc-api-sync'); ?></option>
                        <option value="24" <?php selected((int) ($settings['full_sync_interval'] ?? 0), 24); ?>><?php esc_html_e('Daily', 'wc-api-sync'); ?></option>
                    </select>
                    <p class="description"><?php esc_html_e('Scheduled full sync for all enabled domains (WP-Cron).', 'wc-api-sync'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="incremental_sync_interval"><?php esc_html_e('Incremental Sync Every', 'wc-api-sync'); ?></label></th>
                <td>
                    <select id="incremental_sync_interval" name="incremental_sync_interval">
                        <option value="0" <?php selected((int) ($settings['incremental_sync_interval'] ?? 0), 0); ?>><?php esc_html_e('Disabled', 'wc-api-sync'); ?></option>
                        <option value="5" <?php selected((int) ($settings['incremental_sync_interval'] ?? 0), 5); ?>><?php esc_html_e('5 minutes', 'wc-api-sync'); ?></option>
                        <option value="15" <?php selected((int) ($settings['incremental_sync_interval'] ?? 0), 15); ?>><?php esc_html_e('15 minutes', 'wc-api-sync'); ?></option>
                        <option value="30" <?php selected((int) ($settings['incremental_sync_interval'] ?? 0), 30); ?>><?php esc_html_e('30 minutes', 'wc-api-sync'); ?></option>
                        <option value="60" <?php selected((int) ($settings['incremental_sync_interval'] ?? 0), 60); ?>><?php esc_html_e('Hourly', 'wc-api-sync'); ?></option>
                    </select>
                    <p class="description"><?php esc_html_e('Scheduled incremental sync (state-table diff, WP-Cron).', 'wc-api-sync'); ?></p>
                </td>
            </tr>
        </table>

        <h2><?php esc_html_e('External Feed', 'wc-api-sync'); ?></h2>
        <table class="form-table">
            <tr>
                <th scope="row"><label for="feed_url"><?php esc_html_e('Feed URL', 'wc-api-sync'); ?></label></th>
                <td>
                    <input type="url" id="feed_url" name="feed_url" value="<?php echo esc_attr($settings['feed_url'] ?? ''); ?>" class="regular-text" placeholder="https://" />
                    <p class="description"><?php esc_html_e('JSON endpoint returning price/stock records. Leave empty to disable the feature.', 'wc-api-sync'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="feed_sku"><?php esc_html_e('SKU Field', 'wc-api-sync'); ?></label></th>
                <td>
                    <input type="text" id="feed_sku" name="feed_sku" value="<?php echo esc_attr($settings['feed_sku'] ?? ''); ?>" class="regular-text" placeholder="sku" />
                    <p class="description"><?php esc_html_e('JSON key used to match products by SKU.', 'wc-api-sync'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="feed_price"><?php esc_html_e('Price Field', 'wc-api-sync'); ?></label></th>
                <td>
                    <input type="text" id="feed_price" name="feed_price" value="<?php echo esc_attr($settings['feed_price'] ?? ''); ?>" class="regular-text" placeholder="price" />
                    <p class="description"><?php esc_html_e('JSON key containing the price.', 'wc-api-sync'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="feed_stock"><?php esc_html_e('Stock Field', 'wc-api-sync'); ?></label></th>
                <td>
                    <input type="text" id="feed_stock" name="feed_stock" value="<?php echo esc_attr($settings['feed_stock'] ?? ''); ?>" class="regular-text" placeholder="stock" />
                    <p class="description"><?php esc_html_e('JSON key containing the stock quantity (0 marks out of stock).', 'wc-api-sync'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="feed_frequency"><?php esc_html_e('Frequency', 'wc-api-sync'); ?></label></th>
                <td>
                    <select id="feed_frequency" name="feed_frequency">
                        <option value="" <?php selected($settings['feed_frequency'] ?? '', ''); ?>><?php esc_html_e('â€” No schedule â€”', 'wc-api-sync'); ?></option>
                        <option value="daily" <?php selected($settings['feed_frequency'] ?? '', 'daily'); ?>><?php esc_html_e('Daily (00:00)', 'wc-api-sync'); ?></option>
                        <option value="twicedaily" <?php selected($settings['feed_frequency'] ?? '', 'twicedaily'); ?>><?php esc_html_e('Twice daily (00:00, 12:00)', 'wc-api-sync'); ?></option>
                        <option value="fourtimesdaily" <?php selected($settings['feed_frequency'] ?? '', 'fourtimesdaily'); ?>><?php esc_html_e('4Ã— daily (00/06/12/18)', 'wc-api-sync'); ?></option>
                        <option value="sixhourly" <?php selected($settings['feed_frequency'] ?? '', 'sixhourly'); ?>><?php esc_html_e('Every 6 hours', 'wc-api-sync'); ?></option>
                        <option value="twelvehourly" <?php selected($settings['feed_frequency'] ?? '', 'twelvehourly'); ?>><?php esc_html_e('Every 12 hours', 'wc-api-sync'); ?></option>
                    </select>
                    <p class="description"><?php esc_html_e('Scheduled runs via Action Scheduler (independent of WP-Cron).', 'wc-api-sync'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="feed_min_records"><?php esc_html_e('Minimum Records', 'wc-api-sync'); ?></label></th>
                <td>
                    <input type="number" id="feed_min_records" name="feed_min_records" value="<?php echo esc_attr($settings['feed_min_records'] ?? 1000); ?>" min="1" />
                    <p class="description"><?php esc_html_e('Safety threshold: runs abort if the feed returns fewer records.', 'wc-api-sync'); ?></p>
                </td>
            </tr>
        </table>

        <h2><?php esc_html_e('Rate Limiting', 'wc-api-sync'); ?></h2>
        <table class="form-table">
            <tr>
                <th scope="row"><label for="rate_limit_max"><?php esc_html_e('Max Concurrent Requests', 'wc-api-sync'); ?></label></th>
                <td>
                    <input type="number" id="rate_limit_max" name="rate_limit_max" value="<?php echo esc_attr($settings['rate_limit_max'] ?? 5); ?>" min="1" max="20" />
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="rate_limit_delay"><?php esc_html_e('Delay Between Requests (ms)', 'wc-api-sync'); ?></label></th>
                <td>
                    <input type="number" id="rate_limit_delay" name="rate_limit_delay" value="<?php echo esc_attr($settings['rate_limit_delay'] ?? 100); ?>" min="0" max="1000" />
                </td>
            </tr>
        </table>
        
        <h2><?php esc_html_e('Image Optimization', 'wc-api-sync'); ?></h2>
        <table class="form-table">
            <tr>
                <th scope="row"><?php esc_html_e('Enable Optimization', 'wc-api-sync'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="image_optimization" value="1" <?php checked(!empty($settings['image_optimization'])); ?> />
                        <?php esc_html_e('Enable image optimization', 'wc-api-sync'); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('WebP Conversion', 'wc-api-sync'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="image_webp" value="1" <?php checked(!empty($settings['image_webp'])); ?> />
                        <?php esc_html_e('Convert images to WebP format', 'wc-api-sync'); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="image_quality"><?php esc_html_e('Image Quality', 'wc-api-sync'); ?></label></th>
                <td>
                    <input type="number" id="image_quality" name="image_quality" value="<?php echo esc_attr($settings['image_quality'] ?? 80); ?>" min="1" max="100" />
                    <p class="description"><?php esc_html_e('Compression quality (1-100).', 'wc-api-sync'); ?></p>
                </td>
            </tr>
        </table>
        
        <h2><?php esc_html_e('Logging', 'wc-api-sync'); ?></h2>
        <table class="form-table">
            <tr>
                <th scope="row"><label for="log_level"><?php esc_html_e('Log Level', 'wc-api-sync'); ?></label></th>
                <td>
                    <select id="log_level" name="log_level">
                        <option value="debug" <?php selected($settings['log_level'] ?? '', 'debug'); ?>><?php esc_html_e('Debug', 'wc-api-sync'); ?></option>
                        <option value="info" <?php selected($settings['log_level'] ?? '', 'info'); ?>><?php esc_html_e('Info', 'wc-api-sync'); ?></option>
                        <option value="warning" <?php selected($settings['log_level'] ?? '', 'warning'); ?>><?php esc_html_e('Warning', 'wc-api-sync'); ?></option>
                        <option value="error" <?php selected($settings['log_level'] ?? '', 'error'); ?>><?php esc_html_e('Error', 'wc-api-sync'); ?></option>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Debug Mode', 'wc-api-sync'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="debug_mode" value="1" <?php checked(!empty($settings['debug_mode'])); ?> />
                        <?php esc_html_e('Enable debug mode', 'wc-api-sync'); ?>
                    </label>
                </td>
            </tr>
        </table>
        
        <?php submit_button(__('Save Settings', 'wc-api-sync')); ?>
    </form>
    
    <hr />
    
    <h2><?php esc_html_e('Sync Status', 'wc-api-sync'); ?></h2>
    <div id="sync-status">
        <p><?php esc_html_e('Loading sync status...', 'wc-api-sync'); ?></p>
    </div>
    
    <h2><?php esc_html_e('Quick Actions', 'wc-api-sync'); ?></h2>
    <p>
        <button type="button" class="button button-secondary" id="sync-all"><?php esc_html_e('Sync All Domains', 'wc-api-sync'); ?></button>
        <button type="button" class="button button-secondary" id="check-updates"><?php esc_html_e('Check for Updates', 'wc-api-sync'); ?></button>
    </p>

    <?php if ($feed_payload_ready) : ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="wc_api_sync_run_feed" />
            <?php wp_nonce_field('wc_api_sync_run_feed'); ?>
            <?php submit_button(__('Run External Feed Now', 'wc-api-sync'), 'primary', 'submit', false); ?>
        </form>
    <?php else : ?>
        <p class="description"><?php esc_html_e('Configure the external feed URL and fields above to enable the manual run button.', 'wc-api-sync'); ?></p>
    <?php endif; ?>
</div>

<script>
jQuery(document).ready(function($) {
    function esc(value) {
        return $('<div>').text(value === null || value === undefined ? '' : value).html();
    }

    function fmt(ts) {
        return new Date(ts * 1000).toLocaleString();
    }

    function loadStatus() {
        $.post(wcApiSync.ajaxUrl, {
            action: 'wc_api_sync_status',
            nonce: wcApiSync.nonce
        }, function(response) {
            if (!response.success || !response.data) {
                return;
            }

            var d = response.data;
            var html = '<table class="widefat striped" style="max-width:760px"><tbody>';

            if (d.queue) {
                html += '<tr><td><strong>Queue</strong></td><td>' + d.queue.pending + ' pending / ' + d.queue.processing + ' processing / ' + d.queue.completed + ' completed / ' + d.queue.failed + ' failed</td></tr>';
            }

            var feedState;
            if (!d.feed.configured) {
                feedState = d.feed.payload_ready ? 'Configured (no schedule)' : 'Not configured';
            } else if (d.feed.running) {
                feedState = 'Running...';
            } else if (d.feed.next) {
                feedState = 'Scheduled â€” next: ' + fmt(d.feed.next);
            } else {
                feedState = 'Configured (schedule pending)';
            }
            html += '<tr><td><strong>External feed</strong></td><td>' + esc(feedState) + '</td></tr>';

            if (d.feed.last) {
                html += '<tr><td><strong>Last feed run</strong></td><td>' + fmt(d.feed.last.finished) + ' â€” ' + d.feed.last.saved + ' saved, ' + d.feed.last.price_updates + ' prices, ' + d.feed.last.stock_updates + ' stock, ' + d.feed.last.unmatched + ' unmatched, ' + d.feed.last.errors + ' errors (' + d.feed.last.duration + 's)</td></tr>';
            }

            if (d.feed.error) {
                html += '<tr><td><strong>Feed error</strong></td><td>' + fmt(d.feed.error.time) + ' â€” ' + esc(d.feed.error.message) + '</td></tr>';
            }

            html += '</tbody></table>';
            $('#sync-status').html(html);
        });
    }

    loadStatus();

    $('#sync-all').on('click', function() {
        var $btn = $(this);
        $btn.prop('disabled', true).text('<?php echo esc_js(__('Syncing...', 'wc-api-sync')); ?>');
        
        $.post(wcApiSync.ajaxUrl, {
            action: 'wc_api_sync_sync_all',
            nonce: wcApiSync.nonce
        }, function(response) {
            $btn.prop('disabled', false).text('<?php echo esc_js(__('Sync All Domains', 'wc-api-sync')); ?>');
            alert(response.data.message);
            loadStatus();
        }).fail(function() {
            $btn.prop('disabled', false).text('<?php echo esc_js(__('Sync All Domains', 'wc-api-sync')); ?>');
        });
    });
    
    $('#check-updates').on('click', function() {
        var $btn = $(this);
        $btn.prop('disabled', true).text('<?php echo esc_js(__('Checking...', 'wc-api-sync')); ?>');
        
        $.post(wcApiSync.ajaxUrl, {
            action: 'wc_api_sync_check_updates',
            nonce: wcApiSync.nonce
        }, function(response) {
            $btn.prop('disabled', false).text('<?php echo esc_js(__('Check for Updates', 'wc-api-sync')); ?>');
            alert(response.data.message);
        }).fail(function() {
            $btn.prop('disabled', false).text('<?php echo esc_js(__('Check for Updates', 'wc-api-sync')); ?>');
        });
    });
});
</script>
