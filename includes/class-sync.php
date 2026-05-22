<?php
/**
 * Stock Sync Engine
 * 
 * Handles the actual CSV fetching and stock synchronization process.
 * Optimized for large datasets (4000+ products/rows).
 */

if (!defined('ABSPATH')) {
    exit;
}

class WSSC_Sync {
    
    /**
     * Batch size for processing
     */
    const BATCH_SIZE = 100;
    
    /**
     * Current sync stats (initialized via get_default_stats())
     */
    private $stats = [];
    
    /**
     * Error messages
     */
    private $error_messages = [];
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->stats = $this->get_default_stats();
        add_action('wssc_sync_event', [$this, 'run_scheduled_sync']);
    }
    
    /**
     * Run scheduled sync (with lock to prevent concurrent runs)
     */
    public function run_scheduled_sync() {
        // Check if already running
        if (WSSC()->scheduler->is_running()) {
            return;
        }
        
        // Set running lock
        WSSC()->scheduler->set_running(true);
        
        try {
            $this->run('scheduled');
        } finally {
            WSSC()->scheduler->set_running(false);
        }
    }
    
    /**
     * Run manual sync
     */
    public function run_manual_sync() {
        return $this->run('manual');
    }
    
    /**
     * Get default stats array
     */
    private function get_default_stats() {
        return [
            'total_rows' => 0,
            'processed' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
            'not_found' => 0,
            'missing_set_zero' => 0,
            'missing_set_private' => 0,
            'missing_restored' => 0,
            'start_time' => 0,
            'end_time' => 0,
        ];
    }
    
    /**
     * Main sync process
     */
    public function run($trigger = 'manual') {
        // Reset stats for this run
        $this->stats = $this->get_default_stats();
        $this->error_messages = [];
        
        // Check license
        if (!WSSC()->license->is_valid()) {
            $this->log_error(__('Invalid or expired license. Sync aborted.', 'woo-stock-sync'));
            return [
                'success' => false,
                'message' => __('Invalid or expired license.', 'woo-stock-sync'),
            ];
        }
        
        // Get settings
        $csv_url = get_option('wssc_csv_url');
        $sku_column = get_option('wssc_sku_column', 'sku');
        $qty_column = get_option('wssc_quantity_column', 'quantity');
        
        if (empty($csv_url)) {
            $this->log_error(__('CSV URL is not configured.', 'woo-stock-sync'));
            return [
                'success' => false,
                'message' => __('CSV URL is not configured.', 'woo-stock-sync'),
            ];
        }
        
        // Initialize stats
        $this->stats['start_time'] = microtime(true);
        $this->stats['trigger'] = $trigger;
        
        // Set up error handling
        set_time_limit(0);
        wp_raise_memory_limit('admin');
        
        // Fetch CSV
        $csv_data = $this->fetch_csv($csv_url);
        
        if (!$csv_data['success']) {
            $this->log_sync($trigger, false, $csv_data['message']);
            return $csv_data;
        }
        
        // Parse CSV
        $parsed = $this->parse_csv($csv_data['data'], $sku_column, $qty_column);
        
        if (!$parsed['success']) {
            $this->log_sync($trigger, false, $parsed['message']);
            return $parsed;
        }
        
        $this->stats['total_rows'] = count($parsed['data']);
        
        // Process in batches
        $batches = array_chunk($parsed['data'], self::BATCH_SIZE, true);
        
        foreach ($batches as $batch) {
            $this->process_batch($batch);
        }
        
        // Handle missing SKU action (products in store but not in CSV)
        $missing_sku_action = get_option('wssc_missing_sku_action', 'ignore');
        if ($missing_sku_action !== 'ignore') {
            $this->process_missing_skus($parsed['data'], $missing_sku_action);
        }
        
        // Finalize
        $this->stats['end_time'] = microtime(true);
        $duration = round($this->stats['end_time'] - $this->stats['start_time'], 2);
        
        // Log the sync
        $this->log_sync($trigger, true, null, $duration);
        
        // Re-schedule next sync
        WSSC()->scheduler->reschedule();
        
        return [
            'success' => true,
            'message' => sprintf(
                __('Sync completed. Updated: %d, Skipped: %d, Not found: %d, Errors: %d', 'woo-stock-sync'),
                $this->stats['updated'],
                $this->stats['skipped'],
                $this->stats['not_found'],
                $this->stats['errors']
            ),
            'stats' => $this->stats,
        ];
    }
    
    /**
     * Fetch CSV from URL
     */
    private function fetch_csv($url, $disable_ssl = null) {
        if ($disable_ssl === null) {
            $disable_ssl = get_option('wssc_disable_ssl', false);
        }
        
        $response = wp_remote_get($url, [
            'timeout' => 120,
            'sslverify' => !$disable_ssl,
        ]);
        
        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => sprintf(
                    __('Failed to fetch CSV: %s', 'woo-stock-sync'),
                    $response->get_error_message()
                ),
            ];
        }
        
        $code = wp_remote_retrieve_response_code($response);
        
        if ($code !== 200) {
            return [
                'success' => false,
                'message' => sprintf(
                    __('Failed to fetch CSV. HTTP status: %d', 'woo-stock-sync'),
                    $code
                ),
            ];
        }
        
        $body = wp_remote_retrieve_body($response);
        
        if (empty($body)) {
            return [
                'success' => false,
                'message' => __('CSV file is empty.', 'woo-stock-sync'),
            ];
        }
        
        return [
            'success' => true,
            'data' => $body,
        ];
    }
    
    /**
     * Parse CSV data
     */
    private function parse_csv($csv_content, $sku_column, $qty_column) {
        // Handle BOM
        $csv_content = preg_replace('/^\xEF\xBB\xBF/', '', $csv_content);
        
        // Detect line endings
        $csv_content = str_replace(["\r\n", "\r"], "\n", $csv_content);
        
        $lines = explode("\n", $csv_content);
        $lines = array_filter($lines, function($line) {
            return trim($line) !== '';
        });
        
        if (count($lines) < 2) {
            return [
                'success' => false,
                'message' => __('CSV file must have a header row and at least one data row.', 'woo-stock-sync'),
            ];
        }
        
        // Detect delimiter from header line
        $header_line = array_shift($lines);
        $delimiter = $this->detect_delimiter($header_line);
        
        // Parse header
        $header = str_getcsv($header_line, $delimiter);
        $header = array_map('trim', $header);
        $header = array_map('strtolower', $header);
        
        // Find column indices
        $sku_index = array_search(strtolower($sku_column), $header);
        $qty_index = array_search(strtolower($qty_column), $header);
        
        if ($sku_index === false) {
            return [
                'success' => false,
                'message' => sprintf(
                    __('SKU column "%s" not found in CSV. Available columns: %s', 'woo-stock-sync'),
                    $sku_column,
                    implode(', ', $header)
                ),
            ];
        }
        
        if ($qty_index === false) {
            return [
                'success' => false,
                'message' => sprintf(
                    __('Quantity column "%s" not found in CSV. Available columns: %s', 'woo-stock-sync'),
                    $qty_column,
                    implode(', ', $header)
                ),
            ];
        }
        
        // Parse data rows
        $data = [];
        foreach ($lines as $line) {
            $row = str_getcsv($line, $delimiter);
            
            if (count($row) <= max($sku_index, $qty_index)) {
                continue;
            }
            
            $sku = trim($row[$sku_index]);
            $qty = trim($row[$qty_index]);
            
            if (empty($sku)) {
                continue;
            }
            
            // Clean up quantity
            $qty = preg_replace('/[^0-9.-]/', '', $qty);
            $qty = intval($qty);
            
            $data[$sku] = max(0, $qty);
        }
        
        return [
            'success' => true,
            'data' => $data,
        ];
    }
    
    /**
     * Detect CSV delimiter
     */
    private function detect_delimiter($line) {
        $delimiters = [
            ',' => 0,
            ';' => 0,
            "\t" => 0,
            '|' => 0,
        ];
        
        foreach ($delimiters as $delimiter => &$count) {
            $count = count(str_getcsv($line, $delimiter));
        }
        
        // Return delimiter that produces most columns
        arsort($delimiters);
        return array_key_first($delimiters);
    }
    
    /**
     * Process a batch of SKU => quantity pairs.
     */
    private function process_batch($batch) {
        $skus = array_keys($batch);
        
        // Build product lookup with current stock values
        $products = $this->get_products_by_skus_with_stock($skus);
        
        // Check if we should restore private products to public
        $missing_sku_action = get_option('wssc_missing_sku_action', 'ignore');
        $should_restore_private = ($missing_sku_action === 'private');
        
        // Track products that need to be made public after stock updates
        $products_to_publish = [];
        
        foreach ($batch as $sku => $quantity) {
            $this->stats['processed']++;

            if (!isset($products[$sku])) {
                $this->stats['not_found']++;
                continue;
            }

            $product_data = $products[$sku];
            $product_id = $product_data['id'];
            $current_stock = $product_data['stock'];
            $manages_stock = $product_data['manages_stock'];
            $post_status = $product_data['post_status'];

            // Check if product is private and should be restored to public
            if ($should_restore_private && $post_status === 'private') {
                $products_to_publish[] = $product_id;
            }

            // Skip if stock is already the same
            if ($current_stock !== null && (int) $current_stock === $quantity) {
                $this->stats['skipped']++;
                continue;
            }

            try {
                $this->update_stock($product_id, $quantity, $manages_stock);
                $this->stats['updated']++;
            } catch (Exception $e) {
                $this->stats['errors']++;
                $this->error_messages[] = sprintf(
                    __('Error updating SKU %s: %s', 'woo-stock-sync'),
                    $sku,
                    $e->getMessage()
                );
            }
        }
        
        // Restore private products to public
        if (!empty($products_to_publish)) {
            $this->restore_products_to_public($products_to_publish);
        }
        
        // Clear caches once per batch (not per product)
        $this->clear_product_caches(array_column($products, 'id'));
    }
    
    /**
     * Update stock quantity using WooCommerce stock helpers.
     */
    private function update_stock($product_id, $quantity, $manages_stock = true) {
        $stock_status = $quantity > 0 ? 'instock' : 'outofstock';

        $product = wc_get_product($product_id);

        if (!$product) {
            throw new Exception(
                sprintf(
                    /* translators: %d: product ID */
                    __('Product ID %d could not be loaded.', 'woo-stock-sync'),
                    $product_id
                )
            );
        }

        // Enable stock management if not already
        if (!$manages_stock || !$product->get_manage_stock()) {
            $product->set_manage_stock(true);
            $product->save();
        }

        wc_update_product_stock($product, $quantity, 'set');
        wc_update_product_stock_status($product_id, $stock_status);

        if (function_exists('wc_update_product_lookup_tables')) {
            wc_update_product_lookup_tables($product_id);
        }
    }
    
    /**
     * Get product IDs by SKUs with current stock values (optimized)
     */
    private function get_products_by_skus_with_stock($skus) {
        global $wpdb;
        
        $products = [];
        
        if (empty($skus)) {
            return $products;
        }
        
        $placeholders = array_fill(0, count($skus), '%s');
        $placeholders_str = implode(',', $placeholders);
        
        // Get SKU, ID, stock, manage_stock, and post_status in one query
        $query = $wpdb->prepare(
            "SELECT 
                sku.meta_value as sku, 
                sku.post_id as id,
                stock.meta_value as stock,
                manage.meta_value as manages_stock,
                p.post_status
             FROM {$wpdb->postmeta} sku
             INNER JOIN {$wpdb->posts} p ON sku.post_id = p.ID
             LEFT JOIN {$wpdb->postmeta} stock ON sku.post_id = stock.post_id AND stock.meta_key = '_stock'
             LEFT JOIN {$wpdb->postmeta} manage ON sku.post_id = manage.post_id AND manage.meta_key = '_manage_stock'
             WHERE sku.meta_key = '_sku' 
             AND sku.meta_value IN ($placeholders_str)
             AND p.post_type IN ('product', 'product_variation')
             AND p.post_status IN ('publish', 'private')",
            $skus
        );
        
        $results = $wpdb->get_results($query);
        
        foreach ($results as $row) {
            $products[$row->sku] = [
                'id' => intval($row->id),
                'stock' => $row->stock !== null ? intval($row->stock) : null,
                'manages_stock' => $row->manages_stock === 'yes',
                'post_status' => $row->post_status,
            ];
        }
        
        return $products;
    }
    
    /**
     * Clear product caches efficiently
     */
    private function clear_product_caches($product_ids) {
        if (empty($product_ids)) {
            return;
        }
        
        // Clear WC product cache for affected products only
        foreach ($product_ids as $product_id) {
            wp_cache_delete('product-' . $product_id, 'products');
            wp_cache_delete($product_id, 'posts');
            clean_post_cache($product_id);
        }
        
        // Clear transients once
        wc_delete_product_transients();
    }
    
    /**
     * Restore private products to public status (used when SKU returns to CSV)
     */
    private function restore_products_to_public($product_ids) {
        if (empty($product_ids)) {
            return;
        }
        
        foreach ($product_ids as $product_id) {
            $product = wc_get_product($product_id);
            if (!$product) {
                continue;
            }
            
            // Only restore if currently private
            if ($product->get_status() !== 'private') {
                continue;
            }
            
            // Restore status to publish and catalog visibility to visible (Shop and search results)
            $product->set_status('publish');
            $product->set_catalog_visibility('visible');
            $product->save();
            
            $this->stats['missing_restored']++;
        }
    }
    
    /**
     * Process missing SKUs (products in store but not in CSV)
     * Note: Status changes use $product->save() to trigger WooCommerce hooks
     */
    private function process_missing_skus($csv_data, $action) {
        global $wpdb;
        
        // Get all CSV SKUs
        $csv_skus = array_keys($csv_data);
        
        // Get all store products with SKUs
        $store_products = $this->get_all_store_products_with_sku();
        
        // Find products NOT in CSV
        $missing_skus = array_diff(array_keys($store_products), $csv_skus);
        
        // Get previously privatized products by this plugin
        $privatized_by_plugin = get_option('wssc_privatized_products', []);
        
        // Clean up orphaned entries (products that no longer exist)
        $privatized_by_plugin = $this->cleanup_privatized_products($privatized_by_plugin);
        
        // First, restore products that ARE back in CSV (for private action)
        if ($action === 'private') {
            $returned_skus = array_intersect(array_keys($privatized_by_plugin), $csv_skus);
            foreach ($returned_skus as $sku) {
                $product_id = $privatized_by_plugin[$sku];
                $product = wc_get_product($product_id);
                
                if ($product && $product->get_status() === 'private') {
                    // Restore to publish - use WC methods to trigger hooks
                    $product->set_status('publish');
                    $product->set_catalog_visibility('visible');
                    $product->save();
                    
                    $this->stats['missing_restored']++;
                    unset($privatized_by_plugin[$sku]);
                }
            }
            update_option('wssc_privatized_products', $privatized_by_plugin);
        }
        
        if (empty($missing_skus)) {
            return;
        }
        
        // Process missing products
        if ($action === 'zero') {
            foreach ($missing_skus as $sku) {
                if (!isset($store_products[$sku])) {
                    continue;
                }

                $product_id = $store_products[$sku];

                try {
                    $this->update_stock($product_id, 0, true);
                    $this->stats['missing_set_zero']++;
                } catch (Exception $e) {
                    $this->stats['errors']++;
                    $this->error_messages[] = sprintf(
                        __('Error setting missing SKU %s to zero: %s', 'woo-stock-sync'),
                        $sku,
                        $e->getMessage()
                    );
                }
            }
        }
        
        // For 'private' action, use WC methods to trigger hooks
        if ($action === 'private') {
            foreach ($missing_skus as $sku) {
                if (!isset($store_products[$sku])) {
                    continue;
                }
                
                $product_id = $store_products[$sku];
                
                try {
                    $product = wc_get_product($product_id);
                    
                    if (!$product) {
                        continue;
                    }
                    
                    // Set to private if not already - use WC methods to trigger hooks
                    if ($product->get_status() !== 'private') {
                        $product->set_status('private');
                        $product->set_catalog_visibility('hidden');
                        $product->save();
                        
                        // Track that we privatized this product
                        $privatized_by_plugin[$sku] = $product_id;
                        $this->stats['missing_set_private']++;
                    }
                    
                } catch (Exception $e) {
                    $this->error_messages[] = sprintf(
                        __('Error setting SKU %s to private: %s', 'woo-stock-sync'),
                        $sku,
                        $e->getMessage()
                    );
                }
            }
            
            update_option('wssc_privatized_products', $privatized_by_plugin);
        }
    }
    
    /**
     * Get all store products with SKU
     */
    private function get_all_store_products_with_sku() {
        global $wpdb;
        
        $products = [];
        
        // Get all products and variations with SKUs (regardless of stock management)
        $query = "
            SELECT pm_sku.meta_value as sku, pm_sku.post_id
            FROM {$wpdb->postmeta} pm_sku
            INNER JOIN {$wpdb->posts} p ON pm_sku.post_id = p.ID
            WHERE pm_sku.meta_key = '_sku'
            AND pm_sku.meta_value != ''
            AND p.post_type IN ('product', 'product_variation')
            AND p.post_status IN ('publish', 'private')
        ";
        
        $results = $wpdb->get_results($query);
        
        foreach ($results as $row) {
            $products[$row->sku] = intval($row->post_id);
        }
        
        return $products;
    }
    
    /**
     * Log sync result
     */
    private function log_sync($trigger, $success, $error_message = null, $duration = 0) {
        $log_data = [
            'type' => 'sync',
            'trigger' => $trigger,
            'status' => $success ? 'success' : 'error',
            'message' => $success 
                ? sprintf(
                    __('Sync completed in %s seconds', 'woo-stock-sync'),
                    $duration
                )
                : $error_message,
            'stats' => $this->stats,
            'errors' => $this->error_messages,
        ];
        
        WSSC()->logs->add($log_data);
    }
    
    /**
     * Log error
     */
    private function log_error($message) {
        WSSC()->logs->add([
            'type' => 'sync',
            'status' => 'error',
            'message' => $message,
        ]);
    }
    
    /**
     * Test CSV connection
     */
    public function test_connection($url = null, $disable_ssl = null) {
        if (!$url) {
            $url = get_option('wssc_csv_url');
        }
        
        if (empty($url)) {
            return [
                'success' => false,
                'message' => __('CSV URL is empty.', 'woo-stock-sync'),
            ];
        }
        
        // Fetch CSV
        $csv_data = $this->fetch_csv($url, $disable_ssl);
        
        if (!$csv_data['success']) {
            return $csv_data;
        }
        
        // Try to parse
        $sku_column = get_option('wssc_sku_column', 'sku');
        $qty_column = get_option('wssc_quantity_column', 'quantity');
        
        $parsed = $this->parse_csv($csv_data['data'], $sku_column, $qty_column);
        
        if (!$parsed['success']) {
            return $parsed;
        }
        
        return [
            'success' => true,
            'message' => sprintf(
                __('Connection successful! Found %d products in CSV.', 'woo-stock-sync'),
                count($parsed['data'])
            ),
            'count' => count($parsed['data']),
            'sample' => array_slice($parsed['data'], 0, 5, true),
        ];
    }
    
    /**
     * Get CSV columns preview
     */
    public function preview_columns($url = null, $disable_ssl = null) {
        if (!$url) {
            $url = get_option('wssc_csv_url');
        }
        
        if (empty($url)) {
            return [
                'success' => false,
                'message' => __('CSV URL is empty.', 'woo-stock-sync'),
            ];
        }
        
        // Fetch CSV (only first part for preview)
        if ($disable_ssl === null) {
            $disable_ssl = get_option('wssc_disable_ssl', false);
        }
        
        $response = wp_remote_get($url, [
            'timeout' => 30,
            'sslverify' => !$disable_ssl,
        ]);
        
        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => $response->get_error_message(),
            ];
        }
        
        $body = wp_remote_retrieve_body($response);
        
        // Handle BOM
        $body = preg_replace('/^\xEF\xBB\xBF/', '', $body);
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        
        $lines = explode("\n", $body);
        
        if (empty($lines)) {
            return [
                'success' => false,
                'message' => __('CSV file is empty.', 'woo-stock-sync'),
            ];
        }
        
        // Detect delimiter
        $delimiter = $this->detect_delimiter($lines[0]);
        
        $header = str_getcsv($lines[0], $delimiter);
        $header = array_map('trim', $header);
        
        // Get sample data
        $sample_rows = [];
        for ($i = 1; $i < min(6, count($lines)); $i++) {
            if (trim($lines[$i]) !== '') {
                $sample_rows[] = str_getcsv($lines[$i], $delimiter);
            }
        }
        
        return [
            'success' => true,
            'columns' => $header,
            'sample' => $sample_rows,
            'delimiter' => $delimiter === "\t" ? 'tab' : $delimiter,
        ];
    }
    
    /**
     * Clean up orphaned entries from privatized products tracking
     * Removes entries for products that no longer exist or have been manually restored
     *
     * @param array $privatized_by_plugin Current tracking array
     * @return array Cleaned tracking array
     */
    private function cleanup_privatized_products($privatized_by_plugin) {
        if (empty($privatized_by_plugin)) {
            return [];
        }
        
        $cleaned = [];
        
        foreach ($privatized_by_plugin as $sku => $product_id) {
            $product = wc_get_product($product_id);
            
            // Keep only if product exists and is still private
            if ($product && $product->get_status() === 'private') {
                $cleaned[$sku] = $product_id;
            }
        }
        
        // Only update option if something changed
        if (count($cleaned) !== count($privatized_by_plugin)) {
            update_option('wssc_privatized_products', $cleaned);
        }
        
        return $cleaned;
    }
}
