<?php
/**
 * Admin Class
 * 
 * Handles admin menu, pages, and assets.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WSSC_Admin {
    
    /**
     * Menu slug
     */
    const MENU_SLUG = 'woo-stock-sync';
    
    /**
     * Constructor
     */
    public function __construct() {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_init', [$this, 'register_settings']);
    }
    
    /**
     * Add admin menu
     */
    public function add_menu() {
        // Main menu
        add_menu_page(
            __('Stock Sync', 'woo-stock-sync'),
            __('Stock Sync', 'woo-stock-sync'),
            'manage_woocommerce',
            self::MENU_SLUG,
            [$this, 'render_dashboard_page'],
            'dashicons-update',
            56
        );
        
        // Dashboard submenu
        add_submenu_page(
            self::MENU_SLUG,
            __('Dashboard', 'woo-stock-sync'),
            __('Dashboard', 'woo-stock-sync'),
            'manage_woocommerce',
            self::MENU_SLUG,
            [$this, 'render_dashboard_page']
        );
        
        // Logs submenu
        add_submenu_page(
            self::MENU_SLUG,
            __('Sync Logs', 'woo-stock-sync'),
            __('Logs', 'woo-stock-sync'),
            'manage_woocommerce',
            self::MENU_SLUG . '-logs',
            [$this, 'render_logs_page']
        );
        
        // Settings submenu
        add_submenu_page(
            self::MENU_SLUG,
            __('Settings', 'woo-stock-sync'),
            __('Settings', 'woo-stock-sync'),
            'manage_woocommerce',
            self::MENU_SLUG . '-settings',
            [$this, 'render_settings_page']
        );
        
        // License submenu
        add_submenu_page(
            self::MENU_SLUG,
            __('License', 'woo-stock-sync'),
            __('License', 'woo-stock-sync'),
            'manage_woocommerce',
            self::MENU_SLUG . '-license',
            [$this, 'render_license_page']
        );

        // Debug submenu (per-SKU diagnostics)
        add_submenu_page(
            self::MENU_SLUG,
            __('Stock Sync Debug', 'woo-stock-sync'),
            __('Debug', 'woo-stock-sync'),
            'manage_woocommerce',
            self::MENU_SLUG . '-debug',
            [$this, 'render_debug_page']
        );
    }
    
    /**
     * Enqueue admin assets
     */
    public function enqueue_assets($hook) {
        // Only load on our pages
        if (strpos($hook, self::MENU_SLUG) === false) {
            return;
        }
        
        // CSS
        wp_enqueue_style(
            'wssc-admin',
            WSSC_PLUGIN_URL . 'assets/css/admin.css',
            [],
            WSSC_VERSION
        );
        
        // JS
        wp_enqueue_script(
            'wssc-admin',
            WSSC_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery'],
            WSSC_VERSION,
            true
        );
        
        // Localize script
        wp_localize_script('wssc-admin', 'wssc_admin', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wssc_admin_nonce'),
            'strings' => [
                'sync_running' => __('Sync in progress...', 'woo-stock-sync'),
                'sync_complete' => __('Sync completed!', 'woo-stock-sync'),
                'sync_error' => __('Sync failed!', 'woo-stock-sync'),
                'confirm_sync' => __('Are you sure you want to run a manual sync now?', 'woo-stock-sync'),
                'confirm_clear_logs' => __('Are you sure you want to clear all logs?', 'woo-stock-sync'),
                'testing' => __('Testing connection...', 'woo-stock-sync'),
                'saving' => __('Saving...', 'woo-stock-sync'),
                'activating' => __('Activating license...', 'woo-stock-sync'),
                'deactivating' => __('Deactivating license...', 'woo-stock-sync'),
            ],
        ]);
    }
    
    /**
     * Register settings
     */
    public function register_settings() {
        // CSV Settings
        register_setting('wssc_settings', 'wssc_csv_url', [
            'type' => 'string',
            'sanitize_callback' => 'esc_url_raw',
        ]);
        
        register_setting('wssc_settings', 'wssc_sku_column', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'sku',
        ]);
        
        register_setting('wssc_settings', 'wssc_quantity_column', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'quantity',
        ]);
        
        // Schedule Settings
        register_setting('wssc_settings', 'wssc_schedule_interval', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'hourly',
        ]);
        
        register_setting('wssc_settings', 'wssc_enabled', [
            'type' => 'boolean',
            'default' => false,
        ]);
        
        register_setting('wssc_settings', 'wssc_disable_ssl', [
            'type' => 'boolean',
            'default' => false,
        ]);
        
        register_setting('wssc_settings', 'wssc_missing_sku_action', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'ignore',
        ]);
    }
    
    /**
     * Render Dashboard Page
     */
    public function render_dashboard_page() {
        $license_valid = WSSC()->license->is_valid();
        $stats = WSSC()->logs->get_stats(30);
        $scheduler_status = WSSC()->scheduler->get_status();
        $recent_logs = WSSC()->logs->get(['limit' => 5, 'type' => 'sync']);
        $chart_data = WSSC()->logs->get_chart_data(14);
        
        include WSSC_PLUGIN_DIR . 'includes/views/dashboard.php';
    }
    
    /**
     * Render Logs Page
     */
    public function render_logs_page() {
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 20;
        $offset = ($page - 1) * $per_page;
        
        $type_filter = isset($_GET['type']) ? sanitize_text_field($_GET['type']) : null;
        $status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : null;
        
        $logs = WSSC()->logs->get([
            'type' => $type_filter,
            'status' => $status_filter,
            'limit' => $per_page,
            'offset' => $offset,
        ]);
        
        $total = WSSC()->logs->get_count([
            'type' => $type_filter,
            'status' => $status_filter,
        ]);
        
        $total_pages = ceil($total / $per_page);
        
        include WSSC_PLUGIN_DIR . 'includes/views/logs.php';
    }
    
    /**
     * Render Settings Page
     */
    public function render_settings_page() {
        $license_valid = WSSC()->license->is_valid();
        $intervals = WSSC()->scheduler->get_intervals();
        $current_interval = get_option('wssc_schedule_interval', 'hourly');
        $csv_url = get_option('wssc_csv_url', '');
        $sku_column = get_option('wssc_sku_column', 'sku');
        $qty_column = get_option('wssc_quantity_column', 'quantity');
        $enabled = get_option('wssc_enabled', false);
        $disable_ssl = get_option('wssc_disable_ssl', false);
        $missing_sku_action = get_option('wssc_missing_sku_action', 'ignore');
        
        include WSSC_PLUGIN_DIR . 'includes/views/settings.php';
    }
    
    /**
     * Render License Page
     */
    public function render_license_page() {
        $license_key = get_option('wssc_license_key', '');
        $license_status = get_option('wssc_license_status', '');
        $license_data = WSSC()->license->get_data();
        $last_check = get_option('wssc_license_last_check');
        
        include WSSC_PLUGIN_DIR . 'includes/views/license.php';
    }
    
    /**
     * Render Debug Page
     */
    public function render_debug_page() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have permission to view this page.', 'woo-stock-sync'));
        }

        $sku = isset($_GET['sku']) ? trim(sanitize_text_field(wp_unslash($_GET['sku']))) : '';
        $action_result = null;

        // Handle actions (force update / clear caches)
        if ($sku !== '' && isset($_POST['wssc_debug_action'])) {
            check_admin_referer('wssc_debug_action');
            $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
            $debug_action = sanitize_text_field(wp_unslash($_POST['wssc_debug_action']));
            $action_result = $this->handle_debug_action($debug_action, $product_id, $sku);
        }

        $debug = $sku !== '' ? $this->collect_debug_info($sku) : null;

        include WSSC_PLUGIN_DIR . 'includes/views/debug.php';
    }

    /**
     * Collect everything we know about a SKU from every storage layer.
     *
     * @param string $sku
     * @return array
     */
    private function collect_debug_info($sku) {
        global $wpdb;

        $info = [
            'sku' => $sku,
            'sku_raw_bytes' => $this->hex_bytes($sku),
            'csv' => null,
            'products' => [],
            'plugin_lookup' => null,
            'lookup_table_exists' => false,
        ];

        // 1. Fetch CSV and look up the SKU there
        $csv_url = get_option('wssc_csv_url');
        $sku_column = get_option('wssc_sku_column', 'sku');
        $qty_column = get_option('wssc_quantity_column', 'quantity');

        if (!empty($csv_url)) {
            $disable_ssl = get_option('wssc_disable_ssl', false);
            $response = wp_remote_get($csv_url, [
                'timeout' => 60,
                'sslverify' => !$disable_ssl,
            ]);

            if (is_wp_error($response)) {
                $info['csv'] = ['error' => $response->get_error_message()];
            } else {
                $code = wp_remote_retrieve_response_code($response);
                $body = wp_remote_retrieve_body($response);
                $headers = wp_remote_retrieve_headers($response);
                $header_arr = [];
                if (is_object($headers) && method_exists($headers, 'getAll')) {
                    $header_arr = $headers->getAll();
                } elseif (is_array($headers)) {
                    $header_arr = $headers;
                }
                $relevant_headers = [];
                foreach (['date', 'last-modified', 'etag', 'cache-control', 'age', 'x-cache', 'content-length'] as $h) {
                    if (isset($header_arr[$h])) {
                        $relevant_headers[$h] = is_array($header_arr[$h]) ? implode(', ', $header_arr[$h]) : $header_arr[$h];
                    }
                }

                $row = $this->find_sku_in_csv($body, $sku, $sku_column, $qty_column);
                $info['csv'] = [
                    'http_code' => $code,
                    'headers' => $relevant_headers,
                    'body_bytes' => strlen($body),
                    'fetched_at' => current_time('mysql'),
                    'row' => $row,
                ];
            }
        } else {
            $info['csv'] = ['error' => 'CSV URL is not configured.'];
        }

        // 2. Every product whose _sku postmeta equals this SKU
        $products = $wpdb->get_results($wpdb->prepare(
            "SELECT pm.post_id, p.post_type, p.post_status, p.post_title, p.post_parent
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
             WHERE pm.meta_key = '_sku' AND pm.meta_value = %s",
            $sku
        ));

        $lookup_table = $wpdb->prefix . 'wc_product_meta_lookup';
        $info['lookup_table_exists'] = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $lookup_table)) === $lookup_table;

        foreach ($products as $row) {
            $product_id = intval($row->post_id);

            $postmeta = [];
            $meta_keys = ['_stock', '_stock_status', '_manage_stock', '_sku', '_backorders'];
            $placeholders = implode(',', array_fill(0, count($meta_keys), '%s'));
            $meta_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT meta_key, meta_value FROM {$wpdb->postmeta}
                 WHERE post_id = %d AND meta_key IN ({$placeholders})",
                array_merge([$product_id], $meta_keys)
            ));
            foreach ($meta_rows as $mr) {
                $postmeta[$mr->meta_key] = $mr->meta_value;
            }

            $lookup = null;
            if ($info['lookup_table_exists']) {
                $lookup = $wpdb->get_row($wpdb->prepare(
                    "SELECT product_id, sku, stock_quantity, stock_status FROM {$lookup_table} WHERE product_id = %d",
                    $product_id
                ), ARRAY_A);
            }

            // What WC actually returns through its API
            $wc_stock = null;
            $wc_status = null;
            $wc_manages = null;
            if (function_exists('wc_get_product')) {
                $product = wc_get_product($product_id);
                if ($product) {
                    $wc_stock = $product->get_stock_quantity();
                    $wc_status = $product->get_stock_status();
                    $wc_manages = $product->get_manage_stock();
                }
            }

            $info['products'][] = [
                'id' => $product_id,
                'post_type' => $row->post_type,
                'post_status' => $row->post_status,
                'post_title' => $row->post_title,
                'post_parent' => intval($row->post_parent),
                'postmeta' => $postmeta,
                'lookup' => $lookup,
                'wc_product' => [
                    'stock_quantity' => $wc_stock,
                    'stock_status' => $wc_status,
                    'manages_stock' => $wc_manages,
                ],
                'edit_link' => admin_url('post.php?post=' . $product_id . '&action=edit'),
            ];
        }

        // 3. What the plugin's own sync query would see for this SKU
        if (!empty($products)) {
            $sync = new WSSC_Sync();
            $reflection = new ReflectionClass($sync);
            $method = $reflection->getMethod('get_products_by_skus_with_stock');
            $method->setAccessible(true);
            $info['plugin_lookup'] = $method->invoke($sync, [$sku]);
        }

        return $info;
    }

    /**
     * Find a SKU in raw CSV bytes and return the matching row.
     */
    private function find_sku_in_csv($csv_content, $sku, $sku_column, $qty_column) {
        $csv_content = preg_replace('/^\xEF\xBB\xBF/', '', $csv_content);
        $csv_content = str_replace(["\r\n", "\r"], "\n", $csv_content);
        $lines = array_values(array_filter(explode("\n", $csv_content), function($l) { return trim($l) !== ''; }));
        if (count($lines) < 2) {
            return ['error' => 'CSV is empty or has no data rows.'];
        }
        $header_line = $lines[0];

        // Reuse delimiter detection logic
        $delimiters = [',' => 0, ';' => 0, "\t" => 0, '|' => 0];
        foreach ($delimiters as $d => &$count) {
            $count = count(str_getcsv($header_line, $d));
        }
        arsort($delimiters);
        $delimiter = array_key_first($delimiters);

        $header = array_map('trim', str_getcsv($header_line, $delimiter));
        $header_lower = array_map('strtolower', $header);
        $sku_index = array_search(strtolower($sku_column), $header_lower);
        $qty_index = array_search(strtolower($qty_column), $header_lower);

        if ($sku_index === false || $qty_index === false) {
            return [
                'error' => 'SKU or quantity column not found.',
                'detected_columns' => $header,
                'delimiter' => $delimiter === "\t" ? 'tab' : $delimiter,
            ];
        }

        $line_number = 1;
        foreach (array_slice($lines, 1) as $line) {
            $line_number++;
            $row = str_getcsv($line, $delimiter);
            if (!isset($row[$sku_index])) {
                continue;
            }
            $row_sku = trim($row[$sku_index]);
            if (strcasecmp($row_sku, $sku) !== 0) {
                continue;
            }

            $raw_qty = isset($row[$qty_index]) ? $row[$qty_index] : '';
            $cleaned = preg_replace('/[^0-9.-]/', '', $raw_qty);
            $parsed_qty = max(0, intval($cleaned));

            return [
                'line_number' => $line_number,
                'delimiter' => $delimiter === "\t" ? 'tab' : $delimiter,
                'detected_columns' => $header,
                'row_sku' => $row_sku,
                'row_sku_raw_bytes' => $this->hex_bytes($row_sku),
                'raw_quantity' => $raw_qty,
                'raw_quantity_bytes' => $this->hex_bytes((string) $raw_qty),
                'parsed_quantity' => $parsed_qty,
                'raw_row' => $row,
            ];
        }

        return [
            'found' => false,
            'detected_columns' => $header,
            'delimiter' => $delimiter === "\t" ? 'tab' : $delimiter,
            'note' => 'SKU not present in CSV (case-insensitive match).',
        ];
    }

    /**
     * Handle force-update / clear-cache actions from the debug page.
     */
    private function handle_debug_action($action, $product_id, $sku) {
        global $wpdb;

        if ($product_id <= 0) {
            return ['type' => 'error', 'message' => 'Invalid product ID.'];
        }

        if ($action === 'clear_cache') {
            wp_cache_delete('product-' . $product_id, 'products');
            wp_cache_delete($product_id, 'posts');
            clean_post_cache($product_id);
            wc_delete_product_transients($product_id);
            return ['type' => 'success', 'message' => "Cleared caches for product #{$product_id}."];
        }

        if ($action === 'force_update') {
            $qty = isset($_POST['quantity']) ? intval($_POST['quantity']) : 0;

            $sync = new WSSC_Sync();
            $reflection = new ReflectionClass($sync);
            $method = $reflection->getMethod('update_stock_direct');
            $method->setAccessible(true);
            $method->invoke($sync, $product_id, $qty, true);

            wp_cache_delete('product-' . $product_id, 'products');
            wp_cache_delete($product_id, 'posts');
            clean_post_cache($product_id);
            wc_delete_product_transients($product_id);

            return [
                'type' => 'success',
                'message' => "Forced stock for product #{$product_id} to {$qty} (postmeta + HPOS lookup) and cleared caches.",
            ];
        }

        return ['type' => 'error', 'message' => 'Unknown action.'];
    }

    /**
     * Render a hex dump of a string so invisible characters (BOM, NBSP, trailing space) are visible.
     */
    private function hex_bytes($s) {
        if ($s === null || $s === '') {
            return '';
        }
        $out = [];
        for ($i = 0, $n = strlen($s); $i < $n; $i++) {
            $out[] = sprintf('%02X', ord($s[$i]));
        }
        return implode(' ', $out);
    }

    /**
     * Get admin page URL
     */
    public static function get_page_url($page = '') {
        $slug = self::MENU_SLUG;
        if ($page) {
            $slug .= '-' . $page;
        }
        return admin_url('admin.php?page=' . $slug);
    }
}
