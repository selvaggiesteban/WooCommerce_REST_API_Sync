<?php
/**
 * Admin Settings Page
 *
 * @package WooCommerceApiSync
 */

defined('ABSPATH') || exit;

use WooCommerceApiSync\Core\Config;

$config = new Config();
$settings = $config->all();

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
</div>

<script>
jQuery(document).ready(function($) {
    $('#sync-all').on('click', function() {
        var $btn = $(this);
        $btn.prop('disabled', true).text('<?php echo esc_js(__('Syncing...', 'wc-api-sync')); ?>');
        
        $.post(wcApiSync.ajaxUrl, {
            action: 'wc_api_sync_sync_all',
            nonce: wcApiSync.nonce
        }, function(response) {
            $btn.prop('disabled', false).text('<?php echo esc_js(__('Sync All Domains', 'wc-api-sync')); ?>');
            alert(response.data.message);
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
        });
    });
});
</script>
