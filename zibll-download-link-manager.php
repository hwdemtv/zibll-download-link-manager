<?php
/**
 * Plugin Name: 子比主题网盘链接批量管理
 * Plugin URI: https://www.hwdemtv.com
 * Description: 批量获取、修改、删除、导出和导入子比主题文章中的网盘链接地址
 * Version: 1.3.0
 * Author: hwdemtv
 * Author URI: https://www.hwdemtv.com
 * License: GPL v2 or later
 * Text Domain: zibll-download-link-manager
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

// 定义插件常量
define('ZIBLL_DLM_VERSION', '1.3.0');
define('ZIBLL_DLM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ZIBLL_DLM_PLUGIN_URL', plugin_dir_url(__FILE__));

class Zibll_Download_Link_Manager {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_zibll_dlm_get_links', array($this, 'ajax_get_links'));
        add_action('wp_ajax_zibll_dlm_batch_replace_selected', array($this, 'ajax_batch_replace_selected'));
        add_action('wp_ajax_zibll_dlm_batch_delete_selected', array($this, 'ajax_batch_delete_selected'));
        add_action('wp_ajax_zibll_dlm_export', array($this, 'ajax_export'));
        add_action('wp_ajax_zibll_dlm_import', array($this, 'ajax_import'));
        add_action('wp_ajax_zibll_dlm_update_link', array($this, 'ajax_update_link'));
        add_action('wp_ajax_zibll_dlm_get_logs', array($this, 'ajax_get_logs'));
        add_action('wp_ajax_zibll_dlm_clear_logs', array($this, 'ajax_clear_logs'));
        add_action('wp_ajax_zibll_dlm_download_template', array($this, 'ajax_download_template'));
        add_action('admin_init', array($this, 'auto_clear_logs'));
        
        // 创建日志表
        $this->create_log_table();
    }
    
    /**
     * 创建日志表
     */
    private function create_log_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'zibll_dlm_logs';
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            $charset_collate = $wpdb->get_charset_collate();
            $sql = "CREATE TABLE $table_name (
                id mediumint(9) NOT NULL AUTO_INCREMENT,
                time datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
                user_id bigint(20) NOT NULL,
                action_type varchar(50) NOT NULL,
                details text NOT NULL,
                affected_count int(10) NOT NULL,
                PRIMARY KEY  (id)
            ) $charset_collate;";
            
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
        }
    }

    /**
     * 记录操作日志
     */
    private function log_action($action_type, $details, $affected_count = 0) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'zibll_dlm_logs';
        
        $wpdb->insert(
            $table_name,
            array(
                'time' => current_time('mysql'),
                'user_id' => get_current_user_id(),
                'action_type' => $action_type,
                'details' => $details,
                'affected_count' => $affected_count
            )
        );
    }
    
    /**
     * 添加管理菜单
     */
    public function add_admin_menu() {
        add_management_page(
            '网盘链接批量管理',
            '网盘链接管理',
            'manage_options',
            'zibll-download-link-manager',
            array($this, 'admin_page')
        );
    }
    
    /**
     * 加载脚本和样式
     */
    public function enqueue_scripts($hook) {
        if ('tools_page_zibll-download-link-manager' !== $hook) {
            return;
        }
        
        wp_enqueue_style('zibll-dlm-style', ZIBLL_DLM_PLUGIN_URL . 'assets/style.css', array(), ZIBLL_DLM_VERSION);
        wp_enqueue_script('zibll-dlm-script', ZIBLL_DLM_PLUGIN_URL . 'assets/script.js', array('jquery'), ZIBLL_DLM_VERSION, true);
        
        wp_localize_script('zibll-dlm-script', 'zibllDlm', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('zibll_dlm_nonce'),
            'strings' => array(
                'loading' => '处理中...',
                'success' => '操作成功！',
                'error' => '操作失败，请重试。',
                'confirm_delete' => '确定要删除这些链接吗？此操作不可恢复！',
                'no_links_found' => '未找到符合条件的链接',
            )
        ));
    }
    
    /**
     * 获取所有文章中的网盘链接
     */
    public function get_all_download_links($filters = array(), $page = 1, $per_page = 20, $pagination = true) {
        global $wpdb;
        
        $links = array();
        
        // 优化：使用JOIN一次性获取文章信息和Meta数据，避免N+1查询
        $sql_base = "FROM {$wpdb->postmeta} pm 
                JOIN {$wpdb->posts} p ON pm.post_id = p.ID 
                WHERE pm.meta_key = 'posts_zibpay' AND p.post_status = 'publish'";
        
        // 数据库层面的预过滤（提高性能）
        if (!empty($filters['title'])) {
            $title_like = '%' . $wpdb->esc_like($filters['title']) . '%';
            $sql_base .= $wpdb->prepare(" AND p.post_title LIKE %s", $title_like);
        }
        
        if (!empty($filters['search'])) {
            $search_like = '%' . $wpdb->esc_like($filters['search']) . '%';
            $sql_base .= $wpdb->prepare(" AND pm.meta_value LIKE %s", $search_like);
        }
        
        if (!empty($filters['domain'])) {
            $domain_like = '%' . $wpdb->esc_like($filters['domain']) . '%';
            $sql_base .= $wpdb->prepare(" AND pm.meta_value LIKE %s", $domain_like);
        }
        
        $total_posts = 0;
        
        if ($pagination) {
            // 获取总数
            $count_sql = "SELECT COUNT(DISTINCT pm.post_id) " . $sql_base;
            $total_posts = $wpdb->get_var($count_sql);
            
            // 分页查询
            $offset = ($page - 1) * $per_page;
            $sql = "SELECT pm.post_id, pm.meta_value, p.post_title, p.post_date, p.post_date_gmt " . $sql_base;
            $sql .= " ORDER BY p.post_date DESC LIMIT %d OFFSET %d";
            $sql = $wpdb->prepare($sql, $per_page, $offset);
        } else {
            // 不分页（用于导出或全量替换），但在数据库层面仍限制最大数量防止崩溃
            $sql = "SELECT pm.post_id, pm.meta_value, p.post_title, p.post_date, p.post_date_gmt " . $sql_base;
            $sql .= " ORDER BY p.post_date DESC LIMIT 5000"; // 限制5000篇以保护内存
        }
        
        $results = $wpdb->get_results($sql);
        
        foreach ($results as $row) {
            $pay_mate = maybe_unserialize($row->meta_value);
            
            if (empty($pay_mate) || empty($pay_mate['pay_download'])) {
                continue;
            }
            
            $download_array = $this->get_download_array($pay_mate);
            
            if (empty($download_array)) {
                continue;
            }
            
            foreach ($download_array as $index => $download) {
                if (empty($download['link'])) {
                    continue;
                }
                
                $link = trim($download['link']);
                
                // 应用过滤器（二次精确过滤）
                $use_regex = !empty($filters['use_regex']);
                
                if (!empty($filters['title'])) {
                    $title_match = false;
                    if ($use_regex) {
                        // 使用正则表达式匹配
                        $pattern = $filters['title'];
                        // 检查正则表达式是否有效
                        if (@preg_match($pattern, '') !== false) {
                            $title_match = preg_match($pattern, $row->post_title);
                        }
                    } else {
                        // 使用普通字符串匹配
                        $title_match = stripos($row->post_title, $filters['title']) !== false;
                    }
                    if (!$title_match) {
                        continue;
                    }
                }
                
                if (!empty($filters['search'])) {
                    $search_match = false;
                    if ($use_regex) {
                        // 使用正则表达式匹配
                        $pattern = $filters['search'];
                        // 检查正则表达式是否有效
                        if (@preg_match($pattern, '') !== false) {
                            $search_match = preg_match($pattern, $link);
                        }
                    } else {
                        // 使用普通字符串匹配
                        $search_match = stripos($link, $filters['search']) !== false;
                    }
                    if (!$search_match) {
                        continue;
                    }
                }
                
                if (!empty($filters['domain'])) {
                    $domain_match = false;
                    if ($use_regex) {
                        // 使用正则表达式匹配
                        $pattern = $filters['domain'];
                        // 检查正则表达式是否有效
                        if (@preg_match($pattern, '') !== false) {
                            $parsed = parse_url($link);
                            $host = !empty($parsed['host']) ? $parsed['host'] : '';
                            $domain_match = preg_match($pattern, $host) || preg_match($pattern, $link);
                        }
                    } else {
                        // 使用普通字符串匹配
                        $parsed = parse_url($link);
                        // 既检查hostname，也检查完整链接（防止parse_url失败或不完整）
                        $host_match = !empty($parsed['host']) && stripos($parsed['host'], $filters['domain']) !== false;
                        $link_match = stripos($link, $filters['domain']) !== false;
                        $domain_match = $host_match || $link_match;
                    }
                    if (!$domain_match) {
                        continue;
                    }
                }
                
                $links[] = array(
                    'post_id' => intval($row->post_id),
                    'post_title' => $row->post_title,
                    'post_edit_url' => get_edit_post_link($row->post_id),
                    'post_date' => $row->post_date,
                    'post_date_gmt' => $row->post_date_gmt,
                    'index' => $index,
                    'link' => $link,
                    'name' => !empty($download['name']) ? $download['name'] : '',
                    'more' => !empty($download['more']) ? $download['more'] : '',
                    'class' => !empty($download['class']) ? $download['class'] : '',
                );
            }
        }
        
        if ($pagination) {
            return array(
                'links' => $links,
                'total_posts' => $total_posts,
                'total_pages' => ceil($total_posts / $per_page),
                'current_page' => $page
            );
        } else {
            return $links;
        }
    }
    
    /**
     * 获取下载数组（兼容新旧格式）
     */
    private function get_download_array($pay_mate) {
        if (empty($pay_mate['pay_download'])) {
            return array();
        }
        
        // 新版格式（数组）
        if (is_array($pay_mate['pay_download'])) {
            return $pay_mate['pay_download'];
        }
        
        // 旧版格式（字符串，用换行符分隔）
        $down = explode("\r\n", $pay_mate['pay_download']);
        $down_obj = array();
        
        foreach ($down as $down_v) {
            $down_v = explode("|", $down_v);
            if (empty($down_v[0])) {
                continue;
            }
            
            $down_obj[] = array(
                'link' => trim($down_v[0]),
                'name' => !empty($down_v[1]) ? trim($down_v[1]) : '',
                'more' => !empty($down_v[2]) ? trim($down_v[2]) : '',
                'class' => !empty($down_v[3]) ? trim($down_v[3]) : '',
            );
        }
        
        return $down_obj;
    }
    
    /**
     * AJAX: 获取链接列表
     */
    public function ajax_get_links() {
        check_ajax_referer('zibll_dlm_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => '权限不足'));
        }
        
        $filters = array(
            'title' => isset($_POST['title']) ? sanitize_text_field($_POST['title']) : '',
            'search' => isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '',
            'domain' => isset($_POST['domain']) ? sanitize_text_field($_POST['domain']) : '',
            'use_regex' => isset($_POST['use_regex']) && $_POST['use_regex'] === 'true',
        );
        
        $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
        $per_page = isset($_POST['per_page']) ? intval($_POST['per_page']) : 20;
        if ($per_page < 10) $per_page = 10;
        if ($per_page > 200) $per_page = 200; // 限制最大每页条数
        
        $result = $this->get_all_download_links($filters, $page, $per_page, true);
        
        wp_send_json_success(array(
            'links' => $result['links'],
            'count' => count($result['links']),
            'total_posts' => $result['total_posts'],
            'total_pages' => $result['total_pages'],
            'current_page' => $result['current_page'],
            'per_page' => $per_page
        ));
    }
    
    /**
     * AJAX: 导出链接
     */
    public function ajax_export() {
        check_ajax_referer('zibll_dlm_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => '权限不足'));
        }
        
        $filters = array(
            'title' => isset($_POST['title']) ? sanitize_text_field($_POST['title']) : '',
            'search' => isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '',
            'domain' => isset($_POST['domain']) ? sanitize_text_field($_POST['domain']) : '',
            'use_regex' => isset($_POST['use_regex']) && $_POST['use_regex'] === 'true',
        );
        
        // 获取所有链接（不分页）
        $links = $this->get_all_download_links($filters, 1, 20, false);
        
        if (empty($links)) {
            wp_send_json_error(array('message' => '没有可导出的链接'));
        }
        
        // 生成CSV内容
        $csv_content = "\xEF\xBB\xBF"; // UTF-8 BOM，确保Excel正确显示中文
        $csv_content .= "文章标题,链接地址,显示名称,备注信息,类名,文章ID\n";
        
        foreach ($links as $link) {
            $title = str_replace(array('"', "\n", "\r"), array('""', ' ', ' '), $link['post_title']);
            $url = str_replace(array('"', "\n", "\r"), array('""', ' ', ' '), $link['link']);
            $name = str_replace(array('"', "\n", "\r"), array('""', ' ', ' '), isset($link['name']) ? $link['name'] : '');
            $more = str_replace(array('"', "\n", "\r"), array('""', ' ', ' '), isset($link['more']) ? $link['more'] : '');
            $class = str_replace(array('"', "\n", "\r"), array('""', ' ', ' '), isset($link['class']) ? $link['class'] : '');
            $csv_content .= sprintf('"%s","%s","%s","%s","%s",%d' . "\n", $title, $url, $name, $more, $class, $link['post_id']);
        }
        
        // 记录日志
        $filter_details = array();
        if (!empty($filters['title'])) {
            $filter_details[] = '文章标题: ' . $filters['title'];
        }
        if (!empty($filters['search'])) {
            $filter_details[] = '链接包含: ' . $filters['search'];
        }
        if (!empty($filters['domain'])) {
            $filter_details[] = '域名包含: ' . $filters['domain'];
        }
        $filter_str = !empty($filter_details) ? ' (筛选条件: ' . implode(', ', $filter_details) . ')' : '';
        
        $this->log_action(
            'export',
            sprintf("导出链接: %d 个链接%s", count($links), $filter_str),
            count($links)
        );
        
        // 设置响应头
        $filename = 'zibll_download_links_' . date('Y-m-d_His') . '.csv';
        $filename = sanitize_file_name($filename);
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        echo $csv_content;
        exit;
    }
    
    /**
     * AJAX: 导入链接
     */
    public function ajax_import() {
        check_ajax_referer('zibll_dlm_nonce', 'nonce');
        
        // 防止超时
        @set_time_limit(0);
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => '权限不足'));
        }
        
        if (empty($_FILES['import_file']['tmp_name'])) {
            wp_send_json_error(array('message' => '请选择要导入的文件'));
        }
        
        $file = $_FILES['import_file'];
        
        // 检查文件类型
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($file_ext !== 'csv') {
            wp_send_json_error(array('message' => '只支持CSV格式文件'));
        }
        
        $file_path = $file['tmp_name'];
        $csv_titles = array();
        
        // 第一遍：流式读取收集标题
        if (($handle = fopen($file_path, "r")) !== FALSE) {
            // 移除BOM
            $bom = fread($handle, 3);
            if ($bom !== "\xEF\xBB\xBF") {
                rewind($handle);
            }
            
            // 跳过标题行
            fgetcsv($handle);
            
            while (($data = fgetcsv($handle)) !== FALSE) {
                if (count($data) >= 1 && !empty($data[0])) {
                    $csv_titles[] = trim($data[0]);
                }
            }
            fclose($handle);
        } else {
            wp_send_json_error(array('message' => '无法读取文件'));
        }
        
        // 批量获取ID映射
        $title_map = array();
        if (!empty($csv_titles)) {
            $csv_titles = array_unique($csv_titles);
            // 分批查询，避免SQL过长
            $chunks = array_chunk($csv_titles, 100);
            
            global $wpdb;
            foreach ($chunks as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '%s'));
                $sql = "SELECT ID, post_title FROM {$wpdb->posts} WHERE post_title IN ($placeholders) AND post_type = 'post'";
                $results = $wpdb->get_results($wpdb->prepare($sql, $chunk));
                
                foreach ($results as $row) {
                    $title_map[$row->post_title] = $row->ID;
                }
            }
        }
        
        $imported = 0;
        $updated = 0;
        $errors = array();
        $warnings = array();
        $skipped = 0;
        
        // 第二遍：流式处理数据
        if (($handle = fopen($file_path, "r")) !== FALSE) {
            // 移除BOM
            $bom = fread($handle, 3);
            if ($bom !== "\xEF\xBB\xBF") {
                rewind($handle);
            }
            
            // 跳过标题行
            fgetcsv($handle);
            
            $line_num = 0;
            while (($data = fgetcsv($handle)) !== FALSE) {
                $line_num++;
                
                if (count($data) < 2) {
                    $skipped++;
                    continue;
                }
                
                $post_title = trim($data[0]);
                $old_link_url = isset($data[1]) ? trim($data[1]) : ''; // 原链接地址（用于匹配）
                $post_id = isset($data[2]) ? intval($data[2]) : 0;
                $new_link_url = isset($data[3]) ? trim($data[3]) : ''; // 替换后的地址
                $is_format1 = false; // 标记是否为格式1
                
                // 兼容其他格式：
                // 格式1：文章标题,原链接地址,文章ID,替换后的地址（新格式，按截图）
                // 格式2：文章标题,链接地址,显示名称,备注信息,类名,文章ID（旧格式）
                // 格式3：文章标题,链接地址,文章ID（更旧格式）
                
                // 判断格式：如果第2列是数字（文章ID），且第3列是链接，则认为是格式1
                // 否则按旧格式处理
                if (isset($data[2]) && is_numeric($data[2]) && intval($data[2]) > 0 && 
                    isset($data[3]) && !empty(trim($data[3])) && 
                    (strpos(trim($data[3]), 'http') === 0 || strpos(trim($data[3]), '//') === 0)) {
                    // 格式1：文章标题,原链接地址,文章ID,替换后的地址
                    $is_format1 = true;
                    $old_link_url = trim($data[1]);
                    $post_id = intval($data[2]);
                    $new_link_url = trim($data[3]);
                    $link_name = '';
                    $link_more = '';
                    $link_class = '';
                } else {
                    // 格式2或3：文章标题,链接地址,显示名称,备注信息,类名,文章ID
                    // 或：文章标题,链接地址,文章ID
                    $link_url = trim($data[1]); // 链接地址（可能是原链接，也可能是新链接）
                    $link_name = isset($data[2]) ? trim($data[2]) : '';
                    $link_more = isset($data[3]) ? trim($data[3]) : '';
                    $link_class = isset($data[4]) ? trim($data[4]) : '';
                    $post_id = isset($data[5]) ? intval($data[5]) : 0;
                    
                    // 兼容更旧格式：如果没有第3列或第3列是数字，则认为是文章ID
                    if (empty($link_name) && isset($data[2]) && is_numeric($data[2]) && intval($data[2]) > 0) {
                        $post_id = intval($data[2]);
                        $link_name = '';
                        $link_more = '';
                        $link_class = '';
                    }
                    
                    // 对于格式2（标准格式，与导出一致）
                    // 第2列的链接地址用于匹配和替换：
                    // - 如果该链接地址在文章中存在，就用新链接地址（也是第2列）替换它
                    // - 如果不存在，就添加新链接
                    // 所以原链接和新链接都是第2列的值
                    $old_link_url = $link_url;
                    $new_link_url = $link_url;
                }
                
                // 对于格式1，如果替换后的地址为空，使用原链接地址
                if (!empty($old_link_url) && empty($new_link_url)) {
                    $new_link_url = $old_link_url;
                }
                
                if (empty($post_title)) {
                    $skipped++;
                    continue;
                }
                
                // 对于格式1，必须要有原链接地址或替换后的地址
                // 对于格式2，必须要有链接地址
                if (empty($old_link_url) && empty($new_link_url)) {
                    $skipped++;
                    continue;
                }
                
                // 数据清洗
                $old_link_url = !empty($old_link_url) ? esc_url_raw($old_link_url) : '';
                $new_link_url = !empty($new_link_url) ? esc_url_raw($new_link_url) : '';
                $link_name = sanitize_text_field($link_name);
                $link_more = sanitize_text_field($link_more);
                $link_class = sanitize_text_field($link_class);
                
                // 查找文章
                $target_post_id = $post_id;
                
                if (!$target_post_id || !get_post($target_post_id)) {
                    // 优化：使用预获取的映射表查找
                    if (isset($title_map[$post_title])) {
                        $target_post_id = $title_map[$post_title];
                    } else {
                        $errors[] = "第" . ($line_num + 1) . "行：未找到文章《{$post_title}》";
                        continue;
                    }
                }
                
                // 获取或创建pay_mate
                $pay_mate = get_post_meta($target_post_id, 'posts_zibpay', true);
                if (empty($pay_mate)) {
                    $pay_mate = array(
                        'pay_type' => '2', // 付费下载类型
                        'pay_download' => array()
                    );
                }
                
                // 获取下载数组
                $download_array = $this->get_download_array($pay_mate);
                
                // 查找要替换的链接
                $found = false;
                $target_index = -1;
                
                // 优先通过原链接地址匹配（适用于格式1和格式2）
                $old_link_found = false;
                if (!empty($old_link_url)) {
                    foreach ($download_array as $index => $download) {
                        if (!empty($download['link']) && $download['link'] === $old_link_url) {
                            $found = true;
                            $old_link_found = true;
                            $target_index = $index;
                            break;
                        }
                    }
                }
                
                // 如果通过原链接地址没匹配到，尝试通过新链接地址匹配（适用于格式2中用户修改了链接地址的情况）
                if (!$found && !empty($new_link_url)) {
                    foreach ($download_array as $index => $download) {
                        if (!empty($download['link']) && $download['link'] === $new_link_url) {
                            $found = true;
                            $target_index = $index;
                            break;
                        }
                    }
                }
                
                // 对于格式1，处理原链接相关的情况
                if ($is_format1) {
                    // 情况1：原链接为空，但有替换后的地址 → 添加为新链接（正常操作，不记录警告）
                    if (empty($old_link_url) && !empty($new_link_url)) {
                        // 检查新链接是否已存在，如果存在则更新，否则添加
                        $new_link_exists = false;
                        $existing_index = -1;
                        foreach ($download_array as $index => $download) {
                            if (!empty($download['link']) && $download['link'] === $new_link_url) {
                                $new_link_exists = true;
                                $existing_index = $index;
                                break;
                            }
                        }
                        
                        if ($new_link_exists) {
                            // 新链接已存在，更新其属性
                            $final_name = !empty($link_name) ? $link_name : (isset($download_array[$existing_index]['name']) ? $download_array[$existing_index]['name'] : '');
                            $final_more = !empty($link_more) ? $link_more : (isset($download_array[$existing_index]['more']) ? $download_array[$existing_index]['more'] : '');
                            $final_class = !empty($link_class) ? $link_class : (isset($download_array[$existing_index]['class']) ? $download_array[$existing_index]['class'] : '');
                            
                            $download_array[$existing_index] = array(
                                'link' => $new_link_url,
                                'name' => $final_name,
                                'more' => $final_more,
                                'class' => $final_class
                            );
                            $imported++;
                        } else {
                            // 新链接不存在，添加为新链接
                            $download_array[] = array(
                                'link' => $new_link_url,
                                'name' => $link_name,
                                'more' => $link_more,
                                'class' => $link_class
                            );
                            $imported++;
                        }
                        
                        $pay_mate['pay_download'] = $download_array;
                        $result = update_post_meta($target_post_id, 'posts_zibpay', $pay_mate);
                        if ($result) {
                            $updated++;
                        } else {
                            $errors[] = "第" . ($line_num + 1) . "行：更新文章ID {$target_post_id} 失败";
                        }
                        continue;
                    }
                    
                    // 情况2：原链接不为空但没找到，且有替换后的地址 → 添加为新链接并记录警告
                    if (!empty($old_link_url) && !$old_link_found && !empty($new_link_url)) {
                        // 检查新链接是否已存在，如果存在则更新，否则添加
                        $new_link_exists = false;
                        $existing_index = -1;
                        foreach ($download_array as $index => $download) {
                            if (!empty($download['link']) && $download['link'] === $new_link_url) {
                                $new_link_exists = true;
                                $existing_index = $index;
                                break;
                            }
                        }
                        
                        if ($new_link_exists) {
                            // 新链接已存在，更新其属性并记录警告
                            $warnings[] = "第" . ($line_num + 1) . "行：文章《{$post_title}》中未找到原链接「{$old_link_url}」，但替换后的地址「{$new_link_url}」已存在，已更新该链接的属性";
                            
                            $final_name = !empty($link_name) ? $link_name : (isset($download_array[$existing_index]['name']) ? $download_array[$existing_index]['name'] : '');
                            $final_more = !empty($link_more) ? $link_more : (isset($download_array[$existing_index]['more']) ? $download_array[$existing_index]['more'] : '');
                            $final_class = !empty($link_class) ? $link_class : (isset($download_array[$existing_index]['class']) ? $download_array[$existing_index]['class'] : '');
                            
                            $download_array[$existing_index] = array(
                                'link' => $new_link_url,
                                'name' => $final_name,
                                'more' => $final_more,
                                'class' => $final_class
                            );
                            $imported++;
                        } else {
                            // 新链接不存在，添加为新链接并记录警告
                            $warnings[] = "第" . ($line_num + 1) . "行：文章《{$post_title}》中未找到原链接「{$old_link_url}」，已添加为新链接「{$new_link_url}」";
                            
                            $download_array[] = array(
                                'link' => $new_link_url,
                                'name' => $link_name,
                                'more' => $link_more,
                                'class' => $link_class
                            );
                            $imported++;
                        }
                        
                        $pay_mate['pay_download'] = $download_array;
                        $result = update_post_meta($target_post_id, 'posts_zibpay', $pay_mate);
                        if ($result) {
                            $updated++;
                        } else {
                            $errors[] = "第" . ($line_num + 1) . "行：更新文章ID {$target_post_id} 失败";
                        }
                        continue;
                    }
                    
                    // 情况3：原链接不为空但没找到，且替换后的地址为空 → 记录错误并跳过
                    if (!empty($old_link_url) && !$old_link_found && empty($new_link_url)) {
                        $errors[] = "第" . ($line_num + 1) . "行：文章《{$post_title}》中未找到原链接「{$old_link_url}」，且替换后的地址为空，已跳过";
                        $skipped++;
                        continue;
                    }
                }
                
                // 正常情况：原链接找到了，进行替换
                if ($found) {
                    // 替换已存在的链接
                    // 如果替换后的地址为空，保留原链接地址；否则使用替换后的地址
                    $final_link_url = !empty($new_link_url) ? $new_link_url : $old_link_url;
                    
                    // 如果属性都为空，保留原有属性；否则更新属性
                    $final_name = !empty($link_name) ? $link_name : (isset($download_array[$target_index]['name']) ? $download_array[$target_index]['name'] : '');
                    $final_more = !empty($link_more) ? $link_more : (isset($download_array[$target_index]['more']) ? $download_array[$target_index]['more'] : '');
                    $final_class = !empty($link_class) ? $link_class : (isset($download_array[$target_index]['class']) ? $download_array[$target_index]['class'] : '');
                    
                    $download_array[$target_index] = array(
                        'link' => $final_link_url,
                        'name' => $final_name,
                        'more' => $final_more,
                        'class' => $final_class
                    );
                    $imported++;
                } else {
                    // 添加新链接（必须有新链接地址）
                    if (!empty($new_link_url)) {
                        $download_array[] = array(
                            'link' => $new_link_url,
                            'name' => $link_name,
                            'more' => $link_more,
                            'class' => $link_class
                        );
                        $imported++;
                    } else {
                        $skipped++;
                        continue;
                    }
                }
                
                $pay_mate['pay_download'] = $download_array;
                $result = update_post_meta($target_post_id, 'posts_zibpay', $pay_mate);
                
                if ($result) {
                    $updated++;
                } else {
                    $errors[] = "第" . ($line_num + 1) . "行：更新文章ID {$target_post_id} 失败";
                }
            }
            fclose($handle);
        }
        
        $message = "导入完成！成功导入/替换 {$imported} 个链接，更新 {$updated} 篇文章";
        if ($skipped > 0) {
            $message .= "，跳过 {$skipped} 条记录";
        }
        if (!empty($warnings)) {
            $message .= "，警告 " . count($warnings) . " 条";
        }
        if (!empty($errors)) {
            $message .= "，错误 " . count($errors) . " 条";
        }
        
        // 记录日志
        if ($imported > 0 || !empty($warnings) || !empty($errors)) {
            $log_details = sprintf("导入链接: %d 个链接，更新 %d 篇文章", $imported, $updated);
            if ($skipped > 0) {
                $log_details .= "，跳过 {$skipped} 条记录";
            }
            if (!empty($warnings)) {
                $log_details .= "，警告 " . count($warnings) . " 条";
            }
            if (!empty($errors)) {
                $log_details .= "，错误 " . count($errors) . " 条";
            }
            $this->log_action(
                'import',
                $log_details,
                $updated
            );
        }
        
        wp_send_json_success(array(
            'imported' => $imported,
            'updated' => $updated,
            'skipped' => $skipped,
            'warnings' => $warnings,
            'errors' => $errors,
            'message' => $message
        ));
    }
    
    /**
     * AJAX: 批量替换选中的链接
     */
    public function ajax_batch_replace_selected() {
        check_ajax_referer('zibll_dlm_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => '权限不足'));
        }
        
        $search = isset($_POST['search']) ? trim($_POST['search']) : '';
        $replace = isset($_POST['replace']) ? trim($_POST['replace']) : '';
        $selected_links = isset($_POST['selected_links']) ? $_POST['selected_links'] : array();
        
        if (empty($search)) {
            wp_send_json_error(array('message' => '查找内容不能为空'));
        }
        
        if (empty($selected_links) || !is_array($selected_links)) {
            wp_send_json_error(array('message' => '请先选择要替换的链接'));
        }
        
        $updated = 0;
        $errors = array();
        $posts_data = array();
        
        // 按文章ID分组
        foreach ($selected_links as $link_info) {
            $post_id = isset($link_info['post_id']) ? intval($link_info['post_id']) : 0;
            $index = isset($link_info['index']) ? intval($link_info['index']) : -1;
            
            if (!$post_id || $index < 0) {
                continue;
            }
            
            if (!isset($posts_data[$post_id])) {
                $posts_data[$post_id] = array(
                    'pay_mate' => get_post_meta($post_id, 'posts_zibpay', true),
                    'links' => array()
                );
            }
            
            $posts_data[$post_id]['links'][] = array('index' => $index);
        }
        
        // 批量更新
        foreach ($posts_data as $post_id => $data) {
            $pay_mate = $data['pay_mate'];
            if (empty($pay_mate)) {
                continue;
            }
            
            $download_array = $this->get_download_array($pay_mate);
            $modified = false;
            
            foreach ($data['links'] as $link_info) {
                $index = $link_info['index'];
                if (isset($download_array[$index]) && !empty($download_array[$index]['link'])) {
                    $old_link = $download_array[$index]['link'];
                    $new_link = str_replace($search, $replace, $old_link);
                    
                    if ($old_link !== $new_link) {
                        $download_array[$index]['link'] = $new_link;
                        $modified = true;
                    }
                }
            }
            
            if ($modified) {
                $pay_mate['pay_download'] = $download_array;
                $result = update_post_meta($post_id, 'posts_zibpay', $pay_mate);
                
                if ($result) {
                    $updated++;
                } else {
                    $errors[] = "文章ID {$post_id} 更新失败";
                }
            }
        }
        
        // 记录日志
        if ($updated > 0) {
            $this->log_action(
                'batch_replace',
                sprintf("批量替换: %s -> %s (字段: %s)", $search, $replace, $target_field),
                $updated
            );
        }
        
        wp_send_json_success(array(
            'updated' => $updated,
            'errors' => $errors,
            'message' => "成功更新 {$updated} 篇文章中的选中链接"
        ));
    }
    
    /**
     * AJAX: 批量删除选中的链接
     */
    public function ajax_batch_delete_selected() {
        check_ajax_referer('zibll_dlm_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => '权限不足'));
        }
        
        $selected_links = isset($_POST['selected_links']) ? $_POST['selected_links'] : array();
        
        if (empty($selected_links) || !is_array($selected_links)) {
            wp_send_json_error(array('message' => '请先选择要删除的链接'));
        }
        
        $updated = 0;
        $deleted_count = 0;
        $errors = array();
        $posts_data = array();
        
        // 按文章ID分组
        foreach ($selected_links as $link_info) {
            $post_id = isset($link_info['post_id']) ? intval($link_info['post_id']) : 0;
            $index = isset($link_info['index']) ? intval($link_info['index']) : -1;
            
            if (!$post_id || $index < 0) {
                continue;
            }
            
            if (!isset($posts_data[$post_id])) {
                $posts_data[$post_id] = array(
                    'pay_mate' => get_post_meta($post_id, 'posts_zibpay', true),
                    'links' => array()
                );
            }
            
            $posts_data[$post_id]['links'][] = array('index' => $index);
        }
        
        // 批量删除
        foreach ($posts_data as $post_id => $data) {
            $pay_mate = $data['pay_mate'];
            if (empty($pay_mate)) {
                continue;
            }
            
            $download_array = $this->get_download_array($pay_mate);
            $modified = false;
            
            // 需要删除的索引（倒序排列，避免删除时索引变化）
            $delete_indexes = array();
            foreach ($data['links'] as $link_info) {
                $delete_indexes[] = $link_info['index'];
            }
            rsort($delete_indexes);
            
            foreach ($delete_indexes as $index) {
                if (isset($download_array[$index])) {
                    unset($download_array[$index]);
                    $modified = true;
                    $deleted_count++;
                }
            }
            
            if ($modified) {
                // 重新索引数组
                $download_array = array_values($download_array);
                $pay_mate['pay_download'] = $download_array;
                $result = update_post_meta($post_id, 'posts_zibpay', $pay_mate);
                
                if ($result) {
                    $updated++;
                } else {
                    $errors[] = "文章ID {$post_id} 更新失败";
                }
            }
        }
        
        // 记录日志
        if ($deleted_count > 0) {
            $this->log_action(
                'batch_delete',
                sprintf("批量删除: %s 个链接 (过滤条件: %s)", $deleted_count, json_encode($filters, JSON_UNESCAPED_UNICODE)),
                $updated
            );
        }
        
        wp_send_json_success(array(
            'updated' => $updated,
            'deleted_count' => $deleted_count,
            'errors' => $errors,
            'message' => "成功从 {$updated} 篇文章中删除了 {$deleted_count} 个链接"
        ));
    }
    
    /**
     * AJAX: 更新单个链接
     */
    public function ajax_update_link() {
        check_ajax_referer('zibll_dlm_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => '权限不足'));
        }
        
        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        $index = isset($_POST['index']) ? intval($_POST['index']) : -1;
        $new_link = isset($_POST['link']) ? trim($_POST['link']) : '';
        
        if (!$post_id || $index < 0 || empty($new_link)) {
            wp_send_json_error(array('message' => '参数不完整'));
        }
        
        $pay_mate = get_post_meta($post_id, 'posts_zibpay', true);
        if (empty($pay_mate)) {
            wp_send_json_error(array('message' => '文章未找到或没有付费信息'));
        }
        
        $download_array = $this->get_download_array($pay_mate);
        
        if (!isset($download_array[$index])) {
            wp_send_json_error(array('message' => '链接索引不存在'));
        }
        
        // 更新链接
        $download_array[$index]['link'] = $new_link;
        $pay_mate['pay_download'] = $download_array;
        
        $result = update_post_meta($post_id, 'posts_zibpay', $pay_mate);
        
        if ($result) {
            // 记录日志
            $this->log_action(
                'update_link',
                sprintf("更新链接: ID %d, 索引 %d, 新链接: %s", $post_id, $index, $new_link),
                1
            );
            
            wp_send_json_success(array(
                'message' => '链接更新成功',
                'link' => $new_link
            ));
        } else {
            wp_send_json_error(array('message' => '更新失败'));
        }
    }
    
    /**
     * AJAX: 获取日志
     */
    public function ajax_get_logs() {
        check_ajax_referer('zibll_dlm_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => '权限不足'));
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'zibll_dlm_logs';
        
        // 检查表是否存在
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            wp_send_json_success(array('logs' => array()));
            return;
        }
        
        $logs = $wpdb->get_results("SELECT * FROM $table_name ORDER BY time DESC LIMIT 50");
        
        // 获取用户信息
        foreach ($logs as &$log) {
            $user = get_user_by('id', $log->user_id);
            $log->user_name = $user ? $user->display_name : '未知用户';
            $log->time = get_date_from_gmt($log->time, 'Y-m-d H:i:s');
        }
        
        wp_send_json_success(array('logs' => $logs));
    }

    /**
     * AJAX: 清空日志
     */
    public function ajax_clear_logs() {
        check_ajax_referer('zibll_dlm_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => '权限不足'));
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'zibll_dlm_logs';
        
        $result = $wpdb->query("TRUNCATE TABLE $table_name");
        
        if ($result !== false) {
            wp_send_json_success(array('message' => '日志已清空'));
        } else {
            wp_send_json_error(array('message' => '清空失败'));
        }
    }
    
    /**
     * 下载导入模板
     */
    public function ajax_download_template() {
        // 验证nonce
        if (!isset($_GET['nonce']) || !wp_verify_nonce($_GET['nonce'], 'zibll_dlm_download_template')) {
            wp_die('安全验证失败');
        }
        
        if (!current_user_can('manage_options')) {
            wp_die('权限不足');
        }
        
        $template_file = plugin_dir_path(__FILE__) . 'import-template.csv';
        
        if (!file_exists($template_file)) {
            wp_die('模板文件不存在');
        }
        
        // 设置下载头
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="import-template-' . date('Y-m-d') . '.csv"');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        // 输出BOM（Excel识别UTF-8）
        echo "\xEF\xBB\xBF";
        
        // 读取并输出文件内容
        readfile($template_file);
        exit;
    }
    
    /**
     * 自动清理30天前的日志
     */
    public function auto_clear_logs() {
        // 每天只检查一次
        if (get_transient('zibll_dlm_logs_cleanup_checked')) {
            return;
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'zibll_dlm_logs';
        
        // 检查表是否存在
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            return;
        }
        
        // 删除30天前的日志
        $wpdb->query("DELETE FROM $table_name WHERE time < DATE_SUB(NOW(), INTERVAL 30 DAY)");
        
        // 设置缓存，24小时后过期
        set_transient('zibll_dlm_logs_cleanup_checked', true, DAY_IN_SECONDS);
    }

    /**
     * 管理页面
     */
    public function admin_page() {
        ?>
        <div class="wrap zibll-dlm-wrap">
            <h1>子比主题网盘链接批量管理</h1>
            
            <div class="zibll-dlm-container">
                <!-- 搜索筛选区域 -->
                <div class="zibll-dlm-section">
                    <h2>搜索筛选</h2>
                    <div class="zibll-dlm-filters">
                        <div class="zibll-dlm-filter-row">
                            <label>文章标题：</label>
                            <input type="text" id="filter-title" class="regular-text" placeholder="输入文章标题关键词">
                        </div>
                        <div class="zibll-dlm-filter-row">
                            <label>链接包含：</label>
                            <input type="text" id="filter-search" class="regular-text" placeholder="输入链接中的关键词">
                        </div>
                        <div class="zibll-dlm-filter-row">
                            <label>域名包含：</label>
                            <input type="text" id="filter-domain" class="regular-text" placeholder="例如：lanzoul.com、baidu.com、quark.cn">
                        </div>
                        <div class="zibll-dlm-filter-row">
                            <label>
                                <input type="checkbox" id="filter-use-regex" style="margin-top: -2px; margin-right: 5px;"> 使用正则表达式
                            </label>
                            <span class="description" style="margin-left: 10px; color: #666;">启用后，筛选条件将按正则表达式匹配</span>
                        </div>
                        <div class="zibll-dlm-filter-row">
                            <button type="button" id="btn-search" class="button button-primary">搜索链接</button>
                            <button type="button" id="btn-reset" class="button">重置</button>
                        </div>
                    </div>
                </div>
                
                <!-- 链接列表区域 -->
                <div class="zibll-dlm-section">
                    <h2>链接列表 <span id="link-count" class="count"></span></h2>
                    <!-- 列表筛选工具栏 -->
                    <div id="list-filter-toolbar" class="zibll-dlm-list-filter-toolbar">
                        <div class="zibll-dlm-filter-tags">
                            <span class="zibll-dlm-filter-label">快速筛选：</span>
                            <button type="button" class="zibll-dlm-filter-tag" data-filter="domain" data-value="baidu.com">百度网盘</button>
                            <button type="button" class="zibll-dlm-filter-tag" data-filter="domain" data-value="quark.cn">夸克网盘</button>
                            <button type="button" class="zibll-dlm-filter-tag" data-filter="domain" data-value="lanzoul.com">蓝奏云</button>
                            <button type="button" class="zibll-dlm-filter-tag" data-filter="domain" data-value="123865.com">123网盘</button>
                            <button type="button" class="zibll-dlm-filter-tag" data-filter="clear">清除筛选</button>
                        </div>
                        <div class="zibll-dlm-filter-time">
                            <span class="zibll-dlm-filter-label">发布时间：</span>
                            <button type="button" class="zibll-dlm-filter-tag" data-filter="time" data-value="today">今天</button>
                            <button type="button" class="zibll-dlm-filter-tag" data-filter="time" data-value="yesterday">昨天</button>
                            <button type="button" class="zibll-dlm-filter-tag" data-filter="time" data-value="week">最近7天</button>
                            <button type="button" class="zibll-dlm-filter-tag" data-filter="time" data-value="month">最近30天</button>
                            <input type="date" id="filter-date-from" class="zibll-dlm-date-input" placeholder="开始日期" style="width: 140px;">
                            <span style="margin: 0 5px;">至</span>
                            <input type="date" id="filter-date-to" class="zibll-dlm-date-input" placeholder="结束日期" style="width: 140px;">
                            <button type="button" id="btn-filter-date" class="button button-small">应用日期</button>
                        </div>
                        <div class="zibll-dlm-filter-inputs">
                            <input type="text" id="list-filter-text" class="regular-text" placeholder="在链接中搜索关键词..." style="width: 250px;">
                            <button type="button" id="btn-list-filter" class="button button-small">筛选</button>
                            <button type="button" id="btn-list-filter-reset" class="button button-small">重置</button>
                        </div>
                    </div>
                    
                    <div class="zibll-dlm-display-options" style="margin: 10px 0; text-align: right;">
                        <label>每页显示：</label>
                        <select id="per-page-select" style="height: 28px; line-height: 28px; padding: 0 24px 0 8px;">
                            <option value="10">10</option>
                            <option value="20" selected>20</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                            <option value="200">200</option>
                        </select>
                        <span style="color: #666; font-size: 12px;">条</span>
                    </div>

                    <!-- 批量操作工具栏 -->
                    <div id="batch-toolbar" class="zibll-dlm-batch-toolbar" style="display: none;">
                        <div class="zibll-dlm-toolbar-left">
                            <label class="zibll-dlm-checkbox-label">
                                <input type="checkbox" id="select-all-links" class="zibll-dlm-checkbox">
                                <span>全选</span>
                            </label>
                            <span class="zibll-dlm-selected-count">已选择 <strong id="selected-count">0</strong> 个链接</span>
                        </div>
                        <div class="zibll-dlm-toolbar-right">
                            <div class="zibll-dlm-batch-replace-toolbar" style="display: none;">
                                <select id="batch-replace-field-selected" style="max-width: 90px; height: 28px; vertical-align: top;">
                                    <option value="link">链接地址</option>
                                    <option value="name">显示名称</option>
                                    <option value="more">备注信息</option>
                                </select>
                                <input type="text" id="batch-replace-search" class="regular-text" placeholder="查找内容" style="width: 120px;">
                                <input type="text" id="batch-replace-value" class="regular-text" placeholder="替换为" style="width: 120px;">
                                <label style="margin-right: 5px; font-size: 12px; vertical-align: middle;">
                                    <input type="checkbox" id="batch-replace-regex" style="margin-top: -2px;"> 正则
                                </label>
                                <button type="button" id="btn-batch-replace" class="button button-small">批量替换</button>
                            </div>
                            <button type="button" id="btn-batch-delete" class="button button-small button-secondary">批量删除</button>
                            <button type="button" id="btn-cancel-selection" class="button button-small">取消选择</button>
                        </div>
                    </div>
                    <div id="links-container" class="zibll-dlm-links-container">
                        <p class="description">请先使用搜索筛选功能查找链接</p>
                    </div>
                    <!-- 分页控件 -->
                    <div id="zibll-dlm-pagination" class="zibll-dlm-pagination" style="display: none;"></div>
                </div>
                
                <!-- 导出导入区域 -->
                <div class="zibll-dlm-section">
                    <h2>导出/导入</h2>
                    <div class="zibll-dlm-export-import">
                        <div class="zibll-dlm-export-section">
                            <h3>导出链接</h3>
                            <p class="description">导出当前筛选结果中的链接为CSV文件，包含文章标题、链接地址、显示名称、备注信息、类名和文章ID。导出的CSV文件可用于批量更新链接属性。</p>
                            <button type="button" id="btn-export" class="button button-primary">导出CSV</button>
                        </div>
                        <div class="zibll-dlm-import-section">
                            <h3>导入链接（批量更新）</h3>
                            <p class="description"><strong>批量更新链接：</strong>先导出链接为CSV文件，在Excel中修改链接地址或属性，然后重新导入即可批量更新。支持格式：<strong>文章标题,原链接地址,文章ID,替换后的地址</strong>（推荐）或 文章标题,链接地址,显示名称,备注信息,类名,文章ID</p>
                            <form id="import-form" enctype="multipart/form-data">
                                <input type="file" id="import-file" name="import_file" accept=".csv" required>
                                <button type="button" id="btn-import" class="button button-primary">导入CSV</button>
                            </form>
                            <p class="description" style="margin-top: 10px;">
                                <strong>批量更新流程：</strong><br>
                                1. 使用筛选功能找到需要更新的链接<br>
                                2. 点击"导出CSV"按钮，下载包含所有链接信息的CSV文件<br>
                                3. 在Excel中打开CSV文件，修改链接地址或属性<br>
                                4. 保存CSV文件后，点击"导入CSV"按钮上传，系统会自动替换对应的链接<br><br>
                                <a href="<?php echo esc_url(admin_url('admin-ajax.php?action=zibll_dlm_download_template&nonce=' . wp_create_nonce('zibll_dlm_download_template'))); ?>" class="button" style="margin-top: 5px;">下载导入模板</a>
                            </p>
                        </div>
                    </div>
                </div>
                
                <!-- 操作日志区域 -->
                <div class="zibll-dlm-section">
                    <h2>操作日志 
                        <span style="float: right;">
                            <button type="button" id="btn-clear-logs" class="button button-small button-link-delete" style="margin-right: 10px;">清空日志</button>
                            <button type="button" id="btn-refresh-logs" class="button button-small">刷新日志</button>
                        </span>
                    </h2>
                    <div id="logs-container" class="zibll-dlm-logs-container">
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th style="width: 160px;">时间</th>
                                    <th style="width: 120px;">操作人</th>
                                    <th style="width: 120px;">类型</th>
                                    <th>详情</th>
                                    <th style="width: 80px;">影响数量</th>
                                </tr>
                            </thead>
                            <tbody id="logs-body">
                                <tr><td colspan="5">加载中...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                
            </div>
            
            <!-- 加载提示 -->
            <div id="zibll-dlm-loading" class="zibll-dlm-loading" style="display: none;">
                <div class="spinner is-active"></div>
                <span>处理中...</span>
            </div>
            
            <!-- 消息提示 -->
            <div id="zibll-dlm-message" class="notice" style="display: none;"></div>
        </div>
        <?php
    }
}

// 初始化插件
function zibll_dlm_init() {
    return Zibll_Download_Link_Manager::get_instance();
}
add_action('plugins_loaded', 'zibll_dlm_init');

