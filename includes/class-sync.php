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
     * Maximum wall-clock seconds a single sync run may take before it stops
     * processing remaining batches. Acts as a zombie-killer so a stuck run can
     * never live for hours. Override with the 'wssc_max_runtime' filter.
     */
    const MAX_RUNTIME = 1200;

    /**
     * Current sync stats (initialized via get_default_stats())
     */
    private $stats = [];

    /**
     * Error messages
     */
    private $error_messages = [];

    /**
     * Memoized result of the HPOS product meta lookup table existence check.
     */
    private $has_lookup_table = null;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->stats = $this->get_default_stats();
        add_action('wssc_sync_event', [$this, 'run_scheduled_sync']);
    }
    
    /**
     * Run scheduled sync.
     *
     * Concurrency is guarded centrally inside run() via the single-run lock, so both
     * the scheduled and manual paths share one choke point.
     */
    public function run_scheduled_sync() {
        $this->run('scheduled');
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
     * Main sync process.
     *
     * Acquires a single-run lock up front so overlapping scheduled/manual syncs can
     * never stack up (the original cause of the MySQL query pile-up). The lock is
     * always released afterwards, including on early returns and CSV failures.
     */
    public function run($trigger = 'manual') {
        // Single-run guard shared by every entry point (cron, AJAX, WP-CLI).
        if (!WSSC()->scheduler->acquire_lock()) {
            WSSC()->logs->add([
                'type'    => 'sync',
                'trigger' => $trigger,
                'status'  => 'warning',
                'message' => __('Sync skipped — another sync is already running.', 'woo-stock-sync'),
            ]);

            return [
                'success' => false,
                'message' => __('A sync is already in progress.', 'woo-stock-sync'),
            ];
        }

        try {
            return $this->execute_run($trigger);
        } finally {
            WSSC()->scheduler->release_lock();
        }
    }

    /**
     * Execute a single sync run. Always called while holding the single-run lock.
     */
    private function execute_run($trigger) {
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
        
        // Bound runtime so a single run can never live indefinitely. PHP's own timer
        // is refreshed per batch (below) rather than being disabled outright.
        $max_runtime = (int) apply_filters('wssc_max_runtime', self::MAX_RUNTIME);
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
        
        // Process in batches, honoring the wall-clock budget.
        $batches = array_chunk($parsed['data'], self::BATCH_SIZE, true);

        $time_capped = false;
        foreach ($batches as $batch) {
            if ((microtime(true) - $this->stats['start_time']) > $max_runtime) {
                $time_capped = true;
                break;
            }

            // Keep PHP's execution timer alive per batch without disabling it globally.
            if (function_exists('set_time_limit')) {
                @set_time_limit(120);
            }

            $this->process_batch($batch);
        }

        // Handle missing SKU action (products in store but not in CSV).
        // Skip entirely when the run was time-capped: the catalog view is incomplete,
        // so we must not zero/privatize products we simply never reached this run.
        $missing_sku_action = get_option('wssc_missing_sku_action', 'ignore');
        if (!$time_capped && $missing_sku_action !== 'ignore') {
            $this->process_missing_skus($parsed['data'], $missing_sku_action);
        }

        // Flush WooCommerce product transients once for the whole run (per-product
        // object caches were already cleared per batch).
        wc_delete_product_transients();

        // Finalize
        $this->stats['end_time'] = microtime(true);
        $duration = round($this->stats['end_time'] - $this->stats['start_time'], 2);

        if ($time_capped) {
            WSSC()->logs->add([
                'type'    => 'sync',
                'trigger' => $trigger,
                'status'  => 'warning',
                'message' => sprintf(
                    /* translators: 1: runtime limit in seconds, 2: rows processed, 3: total rows */
                    __('Sync stopped after reaching the %1$ds runtime limit. Processed %2$d of %3$d rows; the rest will sync on the next run.', 'woo-stock-sync'),
                    $max_runtime,
                    $this->stats['processed'],
                    $this->stats['total_rows']
                ),
                'stats'   => $this->stats,
            ]);
        } else {
            // Log the sync
            $this->log_sync($trigger, true, null, $duration);
        }

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
    private function fetch_csv($url) {
        $disable_ssl = get_option('wssc_disable_ssl', false);
        
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
     * Process a batch of SKU => quantity pairs (optimized with direct SQL)
     */
    private function process_batch($batch) {
        global $wpdb;
        
        $skus = array_keys($batch);
        
        // Build product lookup with current stock values
        $products = $this->get_products_by_skus_with_stock($skus);
        
        // Check if we should restore private products to public
        $missing_sku_action = get_option('wssc_missing_sku_action', 'ignore');
        $should_restore_private = ($missing_sku_action === 'private');
        
        // Track products that need to be made public (outside transaction for WC hooks)
        $products_to_publish = [];
        
        // Start transaction for faster writes
        $wpdb->query('START TRANSACTION');
        
        try {
            foreach ($batch as $sku => $quantity) {
                $this->stats['processed']++;

                if (empty($products[$sku])) {
                    $this->stats['not_found']++;
                    continue;
                }

                // A SKU can be shared by multiple posts (multilingual duplicates,
                // accidental copies, etc.). Update every product carrying this SKU,
                // not just one.
                foreach ($products[$sku] as $product_data) {
                    $product_id = $product_data['id'];
                    $current_stock = $product_data['stock'];
                    $lookup_stock = $product_data['lookup_stock'];
                    $manages_stock = $product_data['manages_stock'];
                    $post_status = $product_data['post_status'];

                    if ($should_restore_private && $post_status === 'private') {
                        $products_to_publish[] = $product_id;
                    }

                    // Skip only when both postmeta and HPOS lookup already equal the CSV value.
                    // If they disagree, the row has drifted — force an update so both get repaired.
                    $postmeta_matches = $current_stock !== null && (int) $current_stock === $quantity;
                    $lookup_matches = $lookup_stock === null || (int) $lookup_stock === $quantity;
                    if ($postmeta_matches && $lookup_matches) {
                        $this->stats['skipped']++;
                        continue;
                    }

                    $this->update_stock_direct($product_id, $quantity, $manages_stock);
                    $this->stats['updated']++;
                }
            }

            $wpdb->query('COMMIT');
            
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            $this->stats['errors']++;
            $this->error_messages[] = $e->getMessage();
        }
        
        // Restore private products to public (outside transaction to trigger WC hooks)
        if (!empty($products_to_publish)) {
            $this->restore_products_to_public($products_to_publish);
        }
        
        // Clear caches once per batch (not per product)
        $all_ids = [];
        foreach ($products as $sku_products) {
            foreach ($sku_products as $product_data) {
                $all_ids[] = $product_data['id'];
            }
        }
        $this->clear_product_caches($all_ids);
    }
    
    /**
     * Update stock quantity directly via SQL (bypasses WC hooks for speed)
     */
    private function update_stock_direct($product_id, $quantity, $manages_stock = true) {
        global $wpdb;
        
        $stock_status = $quantity > 0 ? 'instock' : 'outofstock';
        
        // Enable stock management if not already
        if (!$manages_stock) {
            $wpdb->update(
                $wpdb->postmeta,
                ['meta_value' => 'yes'],
                ['post_id' => $product_id, 'meta_key' => '_manage_stock'],
                ['%s'],
                ['%d', '%s']
            );
            
            // Insert if doesn't exist
            if ($wpdb->rows_affected === 0) {
                $wpdb->insert(
                    $wpdb->postmeta,
                    [
                        'post_id' => $product_id,
                        'meta_key' => '_manage_stock',
                        'meta_value' => 'yes',
                    ],
                    ['%d', '%s', '%s']
                );
            }
        }
        
        // Update stock quantity in postmeta
        $wpdb->update(
            $wpdb->postmeta,
            ['meta_value' => $quantity],
            ['post_id' => $product_id, 'meta_key' => '_stock'],
            ['%s'],
            ['%d', '%s']
        );
        
        // Insert if doesn't exist
        if ($wpdb->rows_affected === 0) {
            $wpdb->insert(
                $wpdb->postmeta,
                [
                    'post_id' => $product_id,
                    'meta_key' => '_stock',
                    'meta_value' => $quantity,
                ],
                ['%d', '%s', '%s']
            );
        }
        
        // Update stock status
        $wpdb->update(
            $wpdb->postmeta,
            ['meta_value' => $stock_status],
            ['post_id' => $product_id, 'meta_key' => '_stock_status'],
            ['%s'],
            ['%d', '%s']
        );
        
        // Update HPOS lookup table if it exists
        if ($this->has_lookup_table()) {
            $wpdb->update(
                $wpdb->prefix . 'wc_product_meta_lookup',
                [
                    'stock_quantity' => $quantity,
                    'stock_status' => $stock_status,
                ],
                ['product_id' => $product_id],
                ['%d', '%s'],
                ['%d']
            );
        }
    }
    
    /**
     * Whether the WooCommerce HPOS product meta lookup table exists.
     *
     * Memoized for the request so the SHOW TABLES probe runs at most once instead of
     * on every batch and every per-product stock write.
     *
     * @return bool
     */
    private function has_lookup_table() {
        global $wpdb;

        if ($this->has_lookup_table === null) {
            $lookup_table = $wpdb->prefix . 'wc_product_meta_lookup';
            $this->has_lookup_table = $wpdb->get_var(
                $wpdb->prepare("SHOW TABLES LIKE %s", $lookup_table)
            ) === $lookup_table;
        }

        return $this->has_lookup_table;
    }

    /**
     * Get product IDs by SKUs with current stock values.
     *
     * On HPOS sites (the common case) this drives SKU resolution off the indexed
     * `sku` column of wp_wc_product_meta_lookup instead of scanning the unindexed
     * `wp_postmeta.meta_value`. The remaining detail (postmeta _stock / _manage_stock,
     * post status) is fetched by primary/post_id key, so every query is index-backed.
     * Falls back to the legacy postmeta-driven query when the lookup table is absent.
     *
     * @param string[] $skus
     * @return array<string, array<int, array>> SKU => list of matching product rows.
     */
    private function get_products_by_skus_with_stock($skus) {
        if (empty($skus)) {
            return [];
        }

        if ($this->has_lookup_table()) {
            return $this->get_products_by_skus_via_lookup($skus);
        }

        return $this->get_products_by_skus_via_postmeta($skus);
    }

    /**
     * Index-backed SKU resolution via the HPOS lookup table.
     */
    private function get_products_by_skus_via_lookup($skus) {
        global $wpdb;

        $products = [];
        $lookup_table = $wpdb->prefix . 'wc_product_meta_lookup';
        $placeholders = implode(',', array_fill(0, count($skus), '%s'));

        // 1. Resolve SKU -> product_id off the indexed `sku` column. A SKU can map to
        //    several product_ids (multilingual duplicates, accidental copies).
        $lookup_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT product_id, sku, stock_quantity
             FROM {$lookup_table}
             WHERE sku IN ($placeholders)",
            $skus
        ));

        if (empty($lookup_rows)) {
            return $products;
        }

        // Map product_id => [sku, lookup_stock] and collect ids for the detail fetches.
        $by_id = [];
        foreach ($lookup_rows as $row) {
            $by_id[(int) $row->product_id] = [
                'sku' => $row->sku,
                'lookup_stock' => $row->stock_quantity !== null ? intval($row->stock_quantity) : null,
            ];
        }
        $ids = array_keys($by_id);
        $id_placeholders = implode(',', array_fill(0, count($ids), '%d'));

        // 2. Restrict to the same product types/statuses the original query used.
        //    Uses the posts PRIMARY key.
        $valid = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_status FROM {$wpdb->posts}
             WHERE ID IN ($id_placeholders)
             AND post_type IN ('product', 'product_variation')
             AND post_status IN ('publish', 'private')",
            $ids
        ));

        if (empty($valid)) {
            return $products;
        }

        $status_by_id = [];
        foreach ($valid as $row) {
            $status_by_id[(int) $row->ID] = $row->post_status;
        }
        $valid_ids = array_keys($status_by_id);
        $valid_placeholders = implode(',', array_fill(0, count($valid_ids), '%d'));

        // 3. Fetch _stock and _manage_stock for the valid ids in one query (post_id index).
        $meta = [];
        $meta_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta}
             WHERE post_id IN ($valid_placeholders)
             AND meta_key IN ('_stock', '_manage_stock')",
            $valid_ids
        ));
        foreach ($meta_rows as $row) {
            $meta[(int) $row->post_id][$row->meta_key] = $row->meta_value;
        }

        // 4. Assemble the same shape process_batch() expects.
        foreach ($valid_ids as $id) {
            $sku = $by_id[$id]['sku'];
            $stock_raw = isset($meta[$id]['_stock']) ? $meta[$id]['_stock'] : null;

            if (!isset($products[$sku])) {
                $products[$sku] = [];
            }
            $products[$sku][] = [
                'id' => $id,
                'stock' => $stock_raw !== null ? intval($stock_raw) : null,
                'lookup_stock' => $by_id[$id]['lookup_stock'],
                'manages_stock' => isset($meta[$id]['_manage_stock']) && $meta[$id]['_manage_stock'] === 'yes',
                'post_status' => $status_by_id[$id],
            ];
        }

        return $products;
    }

    /**
     * Legacy postmeta-driven SKU resolution (used when no HPOS lookup table exists).
     */
    private function get_products_by_skus_via_postmeta($skus) {
        global $wpdb;

        $products = [];
        $placeholders_str = implode(',', array_fill(0, count($skus), '%s'));

        $query = $wpdb->prepare(
            "SELECT
                sku.meta_value as sku,
                sku.post_id as id,
                stock.meta_value as stock,
                manage.meta_value as manages_stock,
                p.post_status,
                NULL as lookup_stock
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

        // A SKU can be shared by multiple posts (multilingual duplicates, etc.),
        // so collect every matching product under the SKU key.
        foreach ($results as $row) {
            if (!isset($products[$row->sku])) {
                $products[$row->sku] = [];
            }
            $products[$row->sku][] = [
                'id' => intval($row->id),
                'stock' => $row->stock !== null ? intval($row->stock) : null,
                'lookup_stock' => $row->lookup_stock !== null ? intval($row->lookup_stock) : null,
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
        
        // Clear WC product cache for affected products only.
        // The store-wide wc_delete_product_transients() flush runs once at the end of
        // run() rather than per batch, to avoid repeatedly busting the whole catalog.
        foreach ($product_ids as $product_id) {
            wp_cache_delete('product-' . $product_id, 'products');
            wp_cache_delete($product_id, 'posts');
            clean_post_cache($product_id);
        }
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
                // Old data may have stored a single int; normalize to an array.
                $ids = is_array($privatized_by_plugin[$sku]) ? $privatized_by_plugin[$sku] : [$privatized_by_plugin[$sku]];
                foreach ($ids as $product_id) {
                    $product = wc_get_product($product_id);
                    if ($product && $product->get_status() === 'private') {
                        $product->set_status('publish');
                        $product->set_catalog_visibility('visible');
                        $product->save();

                        $this->stats['missing_restored']++;
                    }
                }
                unset($privatized_by_plugin[$sku]);
            }
            update_option('wssc_privatized_products', $privatized_by_plugin);
        }

        if (empty($missing_skus)) {
            return;
        }

        // Process missing products
        // For 'zero' action, use optimized direct SQL
        if ($action === 'zero') {
            $wpdb->query('START TRANSACTION');
            try {
                foreach ($missing_skus as $sku) {
                    if (empty($store_products[$sku])) {
                        continue;
                    }
                    foreach ($store_products[$sku] as $product_id) {
                        $this->update_stock_direct($product_id, 0, true);
                        $this->stats['missing_set_zero']++;
                    }
                }
                $wpdb->query('COMMIT');
            } catch (Exception $e) {
                $wpdb->query('ROLLBACK');
                $this->error_messages[] = $e->getMessage();
            }
        }

        // For 'private' action, use WC methods to trigger hooks
        if ($action === 'private') {
            foreach ($missing_skus as $sku) {
                if (empty($store_products[$sku])) {
                    continue;
                }

                foreach ($store_products[$sku] as $product_id) {
                    try {
                        $product = wc_get_product($product_id);

                        if (!$product) {
                            continue;
                        }

                        if ($product->get_status() !== 'private') {
                            $product->set_status('private');
                            $product->set_catalog_visibility('hidden');
                            $product->save();

                            if (!isset($privatized_by_plugin[$sku]) || !is_array($privatized_by_plugin[$sku])) {
                                $privatized_by_plugin[$sku] = [];
                            }
                            $privatized_by_plugin[$sku][] = $product_id;
                            $this->stats['missing_set_private']++;
                        }

                    } catch (Exception $e) {
                        $this->error_messages[] = sprintf(
                            __('Error setting SKU %s (#%d) to private: %s', 'woo-stock-sync'),
                            $sku,
                            $product_id,
                            $e->getMessage()
                        );
                    }
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

        if ($this->has_lookup_table()) {
            // HPOS: read SKUs straight from the lookup table, then validate type/status
            // by primary key. Avoids a full scan of the unindexed postmeta._sku values.
            $lookup_table = $wpdb->prefix . 'wc_product_meta_lookup';
            $lookup_rows = $wpdb->get_results(
                "SELECT product_id, sku FROM {$lookup_table} WHERE sku != ''"
            );

            if (empty($lookup_rows)) {
                return $products;
            }

            $sku_by_id = [];
            foreach ($lookup_rows as $row) {
                $sku_by_id[(int) $row->product_id] = $row->sku;
            }
            $ids = array_keys($sku_by_id);
            $id_placeholders = implode(',', array_fill(0, count($ids), '%d'));

            $valid = $wpdb->get_col($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                 WHERE ID IN ($id_placeholders)
                 AND post_type IN ('product', 'product_variation')
                 AND post_status IN ('publish', 'private')",
                $ids
            ));

            foreach ($valid as $id) {
                $id = (int) $id;
                $sku = $sku_by_id[$id];
                if (!isset($products[$sku])) {
                    $products[$sku] = [];
                }
                $products[$sku][] = $id;
            }

            return $products;
        }

        // Legacy fallback: get all products and variations with SKUs from postmeta.
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

        // Each SKU maps to a list of product IDs to handle duplicates.
        foreach ($results as $row) {
            if (!isset($products[$row->sku])) {
                $products[$row->sku] = [];
            }
            $products[$row->sku][] = intval($row->post_id);
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
    public function test_connection($url = null) {
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
        $csv_data = $this->fetch_csv($url);
        
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
    public function preview_columns($url = null) {
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
        $disable_ssl = get_option('wssc_disable_ssl', false);
        
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

        foreach ($privatized_by_plugin as $sku => $value) {
            // Old data may have stored a single int; normalize to an array.
            $ids = is_array($value) ? $value : [$value];
            $kept = [];
            foreach ($ids as $product_id) {
                $product = wc_get_product($product_id);
                if ($product && $product->get_status() === 'private') {
                    $kept[] = intval($product_id);
                }
            }
            if (!empty($kept)) {
                $cleaned[$sku] = $kept;
            }
        }

        // Only update option if something changed
        if ($cleaned !== $privatized_by_plugin) {
            update_option('wssc_privatized_products', $cleaned);
        }
        
        return $cleaned;
    }
}
