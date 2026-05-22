<?php
/**
 * Debug View — per-SKU diagnostics.
 *
 * Variables available:
 * - $sku           string
 * - $debug         array|null
 * - $action_result array|null
 */

if (!defined('ABSPATH')) {
    exit;
}

$render_value = function ($v) {
    if ($v === null) {
        return '<em style="color:#999">null</em>';
    }
    if ($v === '') {
        return '<em style="color:#999">(empty string)</em>';
    }
    if (is_bool($v)) {
        return $v ? '<code>true</code>' : '<code>false</code>';
    }
    return '<code>' . esc_html((string) $v) . '</code>';
};
?>

<div class="wssc-wrap">
    <div class="wssc-header">
        <div class="wssc-header-left">
            <h1><?php esc_html_e('Debug', 'woo-stock-sync'); ?></h1>
            <p class="wssc-subtitle">
                <?php esc_html_e('Inspect a single SKU across every storage layer (CSV, postmeta, HPOS lookup, WooCommerce API).', 'woo-stock-sync'); ?>
            </p>
        </div>
    </div>

    <?php if ($action_result): ?>
        <div class="wssc-notice wssc-notice-<?php echo $action_result['type'] === 'success' ? 'success' : 'warning'; ?>">
            <p><?php echo esc_html($action_result['message']); ?></p>
        </div>
    <?php endif; ?>

    <div class="wssc-section wssc-card">
        <div class="wssc-card-body">
            <form method="get" action="">
                <input type="hidden" name="page" value="woo-stock-sync-debug">
                <div class="wssc-form-row">
                    <label for="wssc-debug-sku" class="wssc-label">
                        <?php esc_html_e('SKU to inspect', 'woo-stock-sync'); ?>
                    </label>
                    <div class="wssc-input-group">
                        <input type="text"
                               id="wssc-debug-sku"
                               name="sku"
                               class="wssc-input"
                               placeholder="ARM-ASN53"
                               value="<?php echo esc_attr($sku); ?>">
                        <button type="submit" class="wssc-btn wssc-btn-primary">
                            <?php esc_html_e('Inspect', 'woo-stock-sync'); ?>
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <?php if ($debug === null): ?>
        <div class="wssc-section">
            <p><?php esc_html_e('Enter a SKU above to see what the sync sees for it.', 'woo-stock-sync'); ?></p>
        </div>
    <?php else: ?>

        <!-- CSV side -->
        <div class="wssc-section wssc-card">
            <div class="wssc-card-header">
                <h2><?php esc_html_e('CSV', 'woo-stock-sync'); ?></h2>
            </div>
            <div class="wssc-card-body">
                <p>
                    <strong><?php esc_html_e('Looked up SKU:', 'woo-stock-sync'); ?></strong>
                    <code><?php echo esc_html($sku); ?></code>
                    &nbsp; <small><?php esc_html_e('hex:', 'woo-stock-sync'); ?> <code><?php echo esc_html($debug['sku_raw_bytes']); ?></code></small>
                </p>

                <?php if (isset($debug['csv']['error'])): ?>
                    <p style="color:#c00"><strong><?php esc_html_e('CSV fetch error:', 'woo-stock-sync'); ?></strong> <?php echo esc_html($debug['csv']['error']); ?></p>
                <?php else: ?>
                    <table class="widefat striped">
                        <tbody>
                            <tr>
                                <th style="width:240px"><?php esc_html_e('HTTP code', 'woo-stock-sync'); ?></th>
                                <td><?php echo $render_value($debug['csv']['http_code']); ?></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Body size (bytes)', 'woo-stock-sync'); ?></th>
                                <td><?php echo $render_value($debug['csv']['body_bytes']); ?></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Fetched at (site time)', 'woo-stock-sync'); ?></th>
                                <td><?php echo $render_value($debug['csv']['fetched_at']); ?></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Cache-relevant response headers', 'woo-stock-sync'); ?></th>
                                <td>
                                    <?php if (empty($debug['csv']['headers'])): ?>
                                        <em><?php esc_html_e('(none reported)', 'woo-stock-sync'); ?></em>
                                    <?php else: ?>
                                        <pre style="margin:0;white-space:pre-wrap"><?php
                                            foreach ($debug['csv']['headers'] as $k => $v) {
                                                echo esc_html($k) . ': ' . esc_html($v) . "\n";
                                            }
                                        ?></pre>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <h3 style="margin-top:24px"><?php esc_html_e('Row match', 'woo-stock-sync'); ?></h3>
                    <?php if (isset($debug['csv']['row']['error'])): ?>
                        <p style="color:#c00"><?php echo esc_html($debug['csv']['row']['error']); ?></p>
                        <?php if (isset($debug['csv']['row']['detected_columns'])): ?>
                            <p><strong><?php esc_html_e('Detected columns:', 'woo-stock-sync'); ?></strong>
                                <code><?php echo esc_html(implode(', ', $debug['csv']['row']['detected_columns'])); ?></code>
                            </p>
                        <?php endif; ?>
                    <?php elseif (isset($debug['csv']['row']['found']) && $debug['csv']['row']['found'] === false): ?>
                        <p><strong style="color:#c00"><?php esc_html_e('SKU not found in CSV.', 'woo-stock-sync'); ?></strong></p>
                        <p><?php echo esc_html($debug['csv']['row']['note']); ?></p>
                        <p><strong><?php esc_html_e('Detected columns:', 'woo-stock-sync'); ?></strong>
                            <code><?php echo esc_html(implode(', ', $debug['csv']['row']['detected_columns'])); ?></code>
                            &nbsp;<small><?php esc_html_e('delimiter:', 'woo-stock-sync'); ?>
                                <code><?php echo esc_html($debug['csv']['row']['delimiter']); ?></code>
                            </small>
                        </p>
                    <?php else: $row = $debug['csv']['row']; ?>
                        <table class="widefat striped">
                            <tbody>
                                <tr>
                                    <th style="width:240px"><?php esc_html_e('Line number', 'woo-stock-sync'); ?></th>
                                    <td><?php echo $render_value($row['line_number']); ?></td>
                                </tr>
                                <tr>
                                    <th><?php esc_html_e('Delimiter', 'woo-stock-sync'); ?></th>
                                    <td><?php echo $render_value($row['delimiter']); ?></td>
                                </tr>
                                <tr>
                                    <th><?php esc_html_e('SKU in row', 'woo-stock-sync'); ?></th>
                                    <td>
                                        <?php echo $render_value($row['row_sku']); ?>
                                        <br><small><?php esc_html_e('hex:', 'woo-stock-sync'); ?> <code><?php echo esc_html($row['row_sku_raw_bytes']); ?></code></small>
                                    </td>
                                </tr>
                                <tr>
                                    <th><?php esc_html_e('Raw quantity (as in CSV)', 'woo-stock-sync'); ?></th>
                                    <td>
                                        <?php echo $render_value($row['raw_quantity']); ?>
                                        <br><small><?php esc_html_e('hex:', 'woo-stock-sync'); ?> <code><?php echo esc_html($row['raw_quantity_bytes']); ?></code></small>
                                    </td>
                                </tr>
                                <tr>
                                    <th><?php esc_html_e('Parsed quantity (what sync would use)', 'woo-stock-sync'); ?></th>
                                    <td>
                                        <strong style="font-size:18px"><?php echo esc_html($row['parsed_quantity']); ?></strong>
                                    </td>
                                </tr>
                                <tr>
                                    <th><?php esc_html_e('Full row', 'woo-stock-sync'); ?></th>
                                    <td><code><?php echo esc_html(implode(' | ', $row['raw_row'])); ?></code></td>
                                </tr>
                            </tbody>
                        </table>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Products side -->
        <div class="wssc-section wssc-card">
            <div class="wssc-card-header">
                <h2><?php esc_html_e('Store products with this SKU', 'woo-stock-sync'); ?></h2>
            </div>
            <div class="wssc-card-body">
                <p>
                    <?php esc_html_e('HPOS lookup table present:', 'woo-stock-sync'); ?>
                    <strong><?php echo $debug['lookup_table_exists'] ? 'yes' : 'no'; ?></strong>
                </p>

                <?php if (empty($debug['products'])): ?>
                    <p style="color:#c00">
                        <strong><?php esc_html_e('No product in the store has _sku =', 'woo-stock-sync'); ?>
                            <code><?php echo esc_html($sku); ?></code>.</strong>
                    </p>
                    <p><?php esc_html_e('This means sync would mark this SKU as "not found" and not update anything.', 'woo-stock-sync'); ?></p>
                <?php else: ?>
                    <?php if (count($debug['products']) > 1): ?>
                        <div class="wssc-notice wssc-notice-warning">
                            <p>
                                <strong><?php esc_html_e('Multiple products share this SKU.', 'woo-stock-sync'); ?></strong>
                                <?php esc_html_e('The sync currently only updates one of them per batch (the rest are dropped during lookup deduplication).', 'woo-stock-sync'); ?>
                            </p>
                        </div>
                    <?php endif; ?>

                    <?php foreach ($debug['products'] as $p): ?>
                        <div style="border:1px solid #ddd;padding:12px;margin-bottom:16px;border-radius:4px">
                            <h3 style="margin-top:0">
                                #<?php echo intval($p['id']); ?> &mdash;
                                <a href="<?php echo esc_url($p['edit_link']); ?>" target="_blank"><?php echo esc_html($p['post_title']); ?></a>
                                <small style="font-weight:normal;color:#666">
                                    [<?php echo esc_html($p['post_type']); ?> / <?php echo esc_html($p['post_status']); ?><?php
                                        if ($p['post_parent'] > 0) {
                                            echo ' / parent #' . intval($p['post_parent']);
                                        }
                                    ?>]
                                </small>
                            </h3>

                            <table class="widefat striped" style="margin-bottom:12px">
                                <thead>
                                    <tr>
                                        <th style="width:240px"><?php esc_html_e('Field', 'woo-stock-sync'); ?></th>
                                        <th><?php esc_html_e('postmeta', 'woo-stock-sync'); ?></th>
                                        <th><?php esc_html_e('wc_product_meta_lookup', 'woo-stock-sync'); ?></th>
                                        <th><?php esc_html_e('wc_get_product()', 'woo-stock-sync'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <th>_sku / sku</th>
                                        <td><?php echo $render_value(isset($p['postmeta']['_sku']) ? $p['postmeta']['_sku'] : null); ?></td>
                                        <td><?php echo $render_value(isset($p['lookup']['sku']) ? $p['lookup']['sku'] : null); ?></td>
                                        <td>&mdash;</td>
                                    </tr>
                                    <tr>
                                        <th>_stock / stock_quantity</th>
                                        <td>
                                            <?php echo $render_value(isset($p['postmeta']['_stock']) ? $p['postmeta']['_stock'] : null); ?>
                                        </td>
                                        <td>
                                            <?php echo $render_value(isset($p['lookup']['stock_quantity']) ? $p['lookup']['stock_quantity'] : null); ?>
                                        </td>
                                        <td>
                                            <?php echo $render_value($p['wc_product']['stock_quantity']); ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>_stock_status / stock_status</th>
                                        <td><?php echo $render_value(isset($p['postmeta']['_stock_status']) ? $p['postmeta']['_stock_status'] : null); ?></td>
                                        <td><?php echo $render_value(isset($p['lookup']['stock_status']) ? $p['lookup']['stock_status'] : null); ?></td>
                                        <td><?php echo $render_value($p['wc_product']['stock_status']); ?></td>
                                    </tr>
                                    <tr>
                                        <th>_manage_stock</th>
                                        <td><?php echo $render_value(isset($p['postmeta']['_manage_stock']) ? $p['postmeta']['_manage_stock'] : null); ?></td>
                                        <td>&mdash;</td>
                                        <td><?php echo $render_value($p['wc_product']['manages_stock']); ?></td>
                                    </tr>
                                    <tr>
                                        <th>_backorders</th>
                                        <td><?php echo $render_value(isset($p['postmeta']['_backorders']) ? $p['postmeta']['_backorders'] : null); ?></td>
                                        <td>&mdash;</td>
                                        <td>&mdash;</td>
                                    </tr>
                                </tbody>
                            </table>

                            <form method="post" style="display:inline-block;margin-right:8px">
                                <?php wp_nonce_field('wssc_debug_action'); ?>
                                <input type="hidden" name="product_id" value="<?php echo intval($p['id']); ?>">
                                <input type="hidden" name="wssc_debug_action" value="clear_cache">
                                <button type="submit" class="wssc-btn">
                                    <?php esc_html_e('Clear caches for this product', 'woo-stock-sync'); ?>
                                </button>
                            </form>

                            <?php
                            $csv_qty = isset($debug['csv']['row']['parsed_quantity']) ? intval($debug['csv']['row']['parsed_quantity']) : null;
                            if ($csv_qty !== null):
                            ?>
                                <form method="post" style="display:inline-block"
                                      onsubmit="return confirm('Force product #<?php echo intval($p['id']); ?> stock to <?php echo intval($csv_qty); ?>?');">
                                    <?php wp_nonce_field('wssc_debug_action'); ?>
                                    <input type="hidden" name="product_id" value="<?php echo intval($p['id']); ?>">
                                    <input type="hidden" name="quantity" value="<?php echo intval($csv_qty); ?>">
                                    <input type="hidden" name="wssc_debug_action" value="force_update">
                                    <button type="submit" class="wssc-btn wssc-btn-primary">
                                        <?php printf(esc_html__('Force update to CSV value (%d)', 'woo-stock-sync'), intval($csv_qty)); ?>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- What the plugin's own batch query returns -->
        <div class="wssc-section wssc-card">
            <div class="wssc-card-header">
                <h2><?php esc_html_e('What the sync\'s own lookup returns', 'woo-stock-sync'); ?></h2>
            </div>
            <div class="wssc-card-body">
                <p><?php esc_html_e('This is exactly what get_products_by_skus_with_stock() hands to process_batch() for this SKU.', 'woo-stock-sync'); ?></p>
                <pre style="background:#f6f7f7;padding:12px;overflow:auto"><?php echo esc_html(print_r($debug['plugin_lookup'], true)); ?></pre>
            </div>
        </div>

    <?php endif; ?>
</div>
