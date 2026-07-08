<?php
/**
 * Admin Logs Page
 *
 * @package WooCommerceApiSync
 */

defined('ABSPATH') || exit;

use WooCommerceApiSync\Utils\Logger;

$logger = new Logger('admin');
$logs = $logger->get_logs(200);
$log_file = $logger->get_log_file_path();

// Handle clear logs
if (isset($_GET['clear_logs']) && current_user_can('manage_woocommerce')) {
    check_admin_referer('wc_api_sync_clear_logs');
    $logger->clear_logs();
    echo '<div class="notice notice-success"><p>' . esc_html__('Logs cleared.', 'wc-api-sync') . '</p></div>';
    $logs = [];
}
?>

<div class="wrap">
    <h1><?php esc_html_e('WooCommerce API Sync Logs', 'wc-api-sync'); ?></h1>
    
    <div class="tablenav top">
        <div class="alignleft actions">
            <a href="<?php echo wp_nonce_url(add_query_arg('clear_logs', '1'), 'wc_api_sync_clear_logs'); ?>" class="button" onclick="return confirm('<?php echo esc_js(__('Are you sure you want to clear all logs?', 'wc-api-sync')); ?>');">
                <?php esc_html_e('Clear Logs', 'wc-api-sync'); ?>
            </a>
            <a href="<?php echo esc_url($log_file); ?>" class="button" target="_blank">
                <?php esc_html_e('Download Log File', 'wc-api-sync'); ?>
            </a>
        </div>
    </div>
    
    <table class="widefat fixed striped" style="margin-top: 10px;">
        <thead>
            <tr>
                <th style="width: 150px;"><?php esc_html_e('Timestamp', 'wc-api-sync'); ?></th>
                <th style="width: 80px;"><?php esc_html_e('Level', 'wc-api-sync'); ?></th>
                <th style="width: 100px;"><?php esc_html_e('Channel', 'wc-api-sync'); ?></th>
                <th><?php esc_html_e('Message', 'wc-api-sync'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($logs)): ?>
                <tr>
                    <td colspan="4"><?php esc_html_e('No logs found.', 'wc-api-sync'); ?></td>
                </tr>
            <?php else: ?>
                <?php foreach ($logs as $log): ?>
                    <?php
                    // Parse log entry
                    if (preg_match('/^\[(.+?)\] \[(.+?)\] \[(.+?)\] (.+)$/', $log, $matches)) {
                        $timestamp = $matches[1];
                        $level = $matches[2];
                        $channel = $matches[3];
                        $message = $matches[4];
                    } else {
                        $timestamp = '';
                        $level = 'INFO';
                        $channel = '';
                        $message = $log;
                    }
                    
                    $level_class = strtolower($level);
                    ?>
                    <tr>
                        <td><code><?php echo esc_html($timestamp); ?></code></td>
                        <td>
                            <span class="wc-api-sync-log-level wc-api-sync-log-<?php echo esc_attr($level_class); ?>">
                                <?php echo esc_html($level); ?>
                            </span>
                        </td>
                        <td><?php echo esc_html($channel); ?></td>
                        <td><?php echo esc_html($message); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<style>
.wc-api-sync-log-level {
    display: inline-block;
    padding: 2px 6px;
    border-radius: 3px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
}

.wc-api-sync-log-debug {
    background: #f0f0f1;
    color: #50575e;
}

.wc-api-sync-log-info {
    background: #d4edda;
    color: #155724;
}

.wc-api-sync-log-warning {
    background: #fff3cd;
    color: #856404;
}

.wc-api-sync-log-error {
    background: #f8d7da;
    color: #721c24;
}
</style>
