<?php
/**
 * Plugin Name: Woo Stock Sync from CSV
 * Plugin URI: https://3ag.app/products/woo-stock-sync-from-csv
 * Description: Automatically sync WooCommerce product stock from a CSV URL on a scheduled basis.
 * Version: 1.4.8
 * Author: 3AG
 * Author URI: https://3ag.app
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: woo-stock-sync
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('WSSC_VERSION', '1.4.8');
define('WSSC_PLUGIN_FILE', __FILE__);
define('WSSC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WSSC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WSSC_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('WSSC_PRODUCT_SLUG', 'woo-stock-sync-from-csv');

/**
 * Get clean domain for license validation
 * 
 * @return string The clean domain
 */
function wssc_get_domain() {
    $site_url = site_url();
    $parsed = wp_parse_url($site_url);
    $domain = isset($parsed['host']) ? $parsed['host'] : '';
    
    // Remove www prefix
    $domain = preg_replace('/^www\./', '', $domain);
    
    // Remove port if present
    $domain = preg_replace('/:\d+$/', '', $domain);
    
    return $domain;
}

// Autoloader
spl_autoload_register(function ($class) {
    $prefix = 'WSSC_';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    
    $class_name = str_replace($prefix, '', $class);
    $class_name = strtolower(str_replace('_', '-', $class_name));
    $file = WSSC_PLUGIN_DIR . 'includes/class-' . $class_name . '.php';
    
    if (file_exists($file)) {
        require_once $file;
    }
});

/**
 * Main Plugin Class
 */
final class Woo_Stock_Sync_From_CSV {
    
    /**
     * Single instance
     */
    private static $instance = null;
    
    /**
     * Plugin components
     */
    public $license;
    public $sync;
    public $scheduler;
    public $logs;
    public $admin;
    public $ajax;
    public $updater;
    
    /**
     * Get instance
     */
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->init_hooks();
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
        
        add_action('plugins_loaded', [$this, 'init']);
        add_action('plugins_loaded', [$this, 'ensure_crons_after_update'], 25);
        add_action('init', [$this, 'load_textdomain']);
    }
    
    /**
     * Initialize plugin
     */
    public function init() {
        // Check WooCommerce dependency
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', [$this, 'woocommerce_missing_notice']);
            return;
        }
        
        // Initialize components
        $this->license = new WSSC_License();
        $this->logs = new WSSC_Logs();
        $this->sync = new WSSC_Sync();
        $this->scheduler = new WSSC_Scheduler();
        $this->ajax = new WSSC_Ajax();
        $this->updater = new WSSC_Updater();
        
        if (is_admin()) {
            $this->admin = new WSSC_Admin();
        }
        
        // HPOS compatibility
        add_action('before_woocommerce_init', function() {
            if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
                \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
            }
        });
    }
    
    /**
     * Load text domain
     */
    public function load_textdomain() {
        load_plugin_textdomain('woo-stock-sync', false, dirname(WSSC_PLUGIN_BASENAME) . '/languages');
    }
    
    /**
     * Activation
     */
    public function activate() {
        // Create logs table
        WSSC_Logs::create_table();
        
        // Set default options
        $defaults = [
            'csv_url' => '',
            'sku_column' => 'sku',
            'quantity_column' => 'quantity',
            'schedule_interval' => 'hourly',
            'enabled' => false,
        ];
        
        foreach ($defaults as $key => $value) {
            if (get_option('wssc_' . $key) === false) {
                update_option('wssc_' . $key, $value);
            }
        }
        
        // Register cron intervals before scheduling
        // (cron_schedules filter may not have run yet during activation)
        $this->register_cron_intervals();
        
        // Schedule watchdog (uses built-in 'hourly' for reliability)
        if (!wp_next_scheduled('wssc_watchdog_check')) {
            wp_schedule_event(time(), 'hourly', 'wssc_watchdog_check');
        }
        
        // Reschedule sync event if sync was previously enabled
        // This ensures sync resumes immediately after reactivation
        if (get_option('wssc_enabled', false) && !wp_next_scheduled('wssc_sync_event')) {
            $interval = get_option('wssc_schedule_interval', 'hourly');
            wp_schedule_event(time(), $interval, 'wssc_sync_event');
        }
        
        flush_rewrite_rules();
    }
    
    /**
     * Register custom cron intervals
     * Called during activation to ensure intervals exist before scheduling
     */
    private function register_cron_intervals() {
        add_filter('cron_schedules', [$this, 'add_cron_intervals_callback']);
    }
    
    /**
     * Cron intervals callback - shared definition for DRY principle
     * This is also used by WSSC_Scheduler::add_cron_intervals()
     *
     * @param array $schedules Existing schedules
     * @return array Modified schedules
     */
    public function add_cron_intervals_callback($schedules) {
        $intervals = self::get_custom_cron_intervals();
        
        foreach ($intervals as $key => $data) {
            if (!isset($schedules[$key])) {
                $schedules[$key] = $data;
            }
        }
        
        return $schedules;
    }
    
    /**
     * Get custom cron intervals definition
     * Single source of truth for all custom intervals
     *
     * @return array Custom intervals
     */
    public static function get_custom_cron_intervals() {
        return [
            'wssc_5min' => [
                'interval' => 5 * MINUTE_IN_SECONDS,
                'display' => __('Every 5 Minutes', 'woo-stock-sync'),
            ],
            'wssc_15min' => [
                'interval' => 15 * MINUTE_IN_SECONDS,
                'display' => __('Every 15 Minutes', 'woo-stock-sync'),
            ],
            'wssc_30min' => [
                'interval' => 30 * MINUTE_IN_SECONDS,
                'display' => __('Every 30 Minutes', 'woo-stock-sync'),
            ],
            'wssc_2hours' => [
                'interval' => 2 * HOUR_IN_SECONDS,
                'display' => __('Every 2 Hours', 'woo-stock-sync'),
            ],
            'wssc_4hours' => [
                'interval' => 4 * HOUR_IN_SECONDS,
                'display' => __('Every 4 Hours', 'woo-stock-sync'),
            ],
            'wssc_6hours' => [
                'interval' => 6 * HOUR_IN_SECONDS,
                'display' => __('Every 6 Hours', 'woo-stock-sync'),
            ],
            'wssc_12hours' => [
                'interval' => 12 * HOUR_IN_SECONDS,
                'display' => __('Every 12 Hours', 'woo-stock-sync'),
            ],
            'wssc_2days' => [
                'interval' => 2 * DAY_IN_SECONDS,
                'display' => __('Every 2 Days', 'woo-stock-sync'),
            ],
        ];
    }
    
    /**
     * Ensure crons are scheduled after a plugin update
     *
     * WordPress update process: deactivate -> replace files -> reactivate.
     * But activate_plugin() called from upgrader_post_install does NOT trigger
     * register_activation_hook, so crons cleared during deactivation are never
     * rescheduled. This method acts as a safety net on every page load.
     */
    public function ensure_crons_after_update() {
        if (!class_exists('WooCommerce') || !$this->scheduler || !$this->license) {
            return;
        }

        // Check if watchdog is missing — this is the canary.
        // If watchdog is gone, it means crons were wiped (most likely by an update).
        if (!wp_next_scheduled('wssc_watchdog_check')) {
            wp_schedule_event(time(), 'hourly', 'wssc_watchdog_check');

            // Reschedule sync if enabled
            $enabled = get_option('wssc_enabled', false);
            if ($enabled && !wp_next_scheduled('wssc_sync_event')) {
                $interval = get_option('wssc_schedule_interval', 'hourly');
                wp_schedule_event(time(), $interval, 'wssc_sync_event');
            }

            // Log the recovery
            if ($this->logs) {
                $this->logs->add([
                    'type'    => 'watchdog',
                    'trigger' => 'system',
                    'status'  => 'warning',
                    'message' => __('Cron recovery: Watchdog was missing (likely after plugin update). All crons rescheduled.', 'woo-stock-sync'),
                ]);
            }
        }

        // Also ensure license check cron exists
        if (!wp_next_scheduled('wssc_license_check')) {
            wp_schedule_event(time(), 'daily', 'wssc_license_check');
        }

        // Also ensure update check cron exists
        if (!wp_next_scheduled('wssc_update_check')) {
            wp_schedule_event(time(), 'twicedaily', 'wssc_update_check');
        }
    }

    /**
     * Deactivation
     */
    public function deactivate() {
        // Clear scheduled events only
        // License should persist across deactivation/reactivation
        wp_clear_scheduled_hook('wssc_sync_event');
        wp_clear_scheduled_hook('wssc_watchdog_check');
        wp_clear_scheduled_hook('wssc_license_check');
        wp_clear_scheduled_hook('wssc_update_check');
        
        // NOTE: We do NOT deactivate the license here
        // License deactivation should only happen when user explicitly clicks "Deactivate License"
        // This allows the plugin to be deactivated/reactivated or updated without losing license
    }
    
    /**
     * WooCommerce missing notice
     */
    public function woocommerce_missing_notice() {
        ?>
        <div class="notice notice-error">
            <p><?php esc_html_e('Woo Stock Sync from CSV requires WooCommerce to be installed and active.', 'woo-stock-sync'); ?></p>
        </div>
        <?php
    }
    
    /**
     * Get plugin URL
     */
    public function plugin_url() {
        return WSSC_PLUGIN_URL;
    }
    
    /**
     * Get plugin path
     */
    public function plugin_path() {
        return WSSC_PLUGIN_DIR;
    }
}

/**
 * Main instance
 */
function WSSC() {
    return Woo_Stock_Sync_From_CSV::instance();
}

// Initialize
WSSC();
