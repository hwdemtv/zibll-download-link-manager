<?php
// 如果不是从 WordPress 调用，则退出
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// 删除日志表
$table_name = $wpdb->prefix . 'zibll_dlm_logs';
$wpdb->query("DROP TABLE IF EXISTS $table_name");

// 删除配置项（如果有）
// delete_option('zibll_dlm_options');

