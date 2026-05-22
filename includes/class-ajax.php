<?php
/**
 * AJAX Handler Class
 * 
 * Handles all AJAX requests for the plugin.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WSSC_Ajax {
    
    /**
     * Constructor
     */
    public function __construct() {
        // Admin AJAX actions
        add_action('wp_ajax_wssc_run_sync', [$this, 'run_sync']);
        add_action('wp_ajax_wssc_test_connection', [$this, 'test_connection']);
        add_action('wp_ajax_wssc_preview_csv', [$this, 'preview_csv']);
        add_action('wp_ajax_wssc_save_settings', [$this, 'save_settings']);
        add_action('wp_ajax_wssc_activate_license', [$this, 'activate_license']);
        add_action('wp_ajax_wssc_activate_domain', [$this, 'activate_domain']);
        add_action('wp_ajax_wssc_deactivate_license', [$this, 'deactivate_license']);
        add_action('wp_ajax_wssc_check_license', [$this, 'check_license']);
        add_action('wp_ajax_wssc_clear_logs', [$this, 'clear_logs']);
        add_action('wp_ajax_wssc_get_log_details', [$this, 'get_log_details']);
        add_action('wp_ajax_wssc_get_sync_status', [$this, 'get_sync_status']);
        add_action('wp_ajax_wssc_toggle_sync', [$this, 'toggle_sync']);
        add_action('wp_ajax_wssc_check_update', [$this, 'check_update']);
        add_action('wp_ajax_wssc_install_update', [$this, 'install_update']);
    }
    
    /**
     * Verify nonce
     */
    private function verify_nonce() {
        if (!check_ajax_referer('wssc_admin_nonce', 'nonce', false)) {
            wp_send_json_error([
                'message' => __('Security check failed.', 'woo-stock-sync'),
            ]);
        }
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error([
                'message' => __('Permission denied.', 'woo-stock-sync'),
            ]);
        }
    }
    
    /**
     * Run manual sync
     */
    public function run_sync() {
        $this->verify_nonce();
        
        // Check if already running
        if (WSSC()->scheduler->is_running()) {
            wp_send_json_error([
                'message' => __('A sync is already in progress.', 'woo-stock-sync'),
            ]);
        }
        
        // Rate limiting: prevent sync within 30 seconds of last manual sync
        $last_manual = get_transient('wssc_last_manual_sync');
        if ($last_manual !== false) {
            $wait_time = 30 - (time() - $last_manual);
            if ($wait_time > 0) {
                wp_send_json_error([
                    'message' => sprintf(
                        /* translators: %d seconds remaining */
                        __('Please wait %d seconds before starting another sync.', 'woo-stock-sync'),
                        $wait_time
                    ),
                ]);
            }
        }
        set_transient('wssc_last_manual_sync', time(), 60);
        
        // Set running status
        WSSC()->scheduler->set_running(true);
        
        try {
            $result = WSSC()->sync->run_manual_sync();
            WSSC()->scheduler->set_running(false);
            
            if ($result['success']) {
                wp_send_json_success($result);
            } else {
                wp_send_json_error($result);
            }
        } catch (Exception $e) {
            WSSC()->scheduler->set_running(false);
            wp_send_json_error([
                'message' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * Test CSV connection
     */
    public function test_connection() {
        $this->verify_nonce();
        
        // Validate license
        if (!WSSC()->license->is_valid()) {
            wp_send_json_error([
                'message' => __('Please activate a valid license first.', 'woo-stock-sync'),
            ]);
        }
        
        $url = isset($_POST['url']) ? esc_url_raw($_POST['url']) : '';
        $disable_ssl = isset($_POST['disable_ssl']) && $_POST['disable_ssl'] === 'true';
        
        if (empty($url)) {
            wp_send_json_error([
                'message' => __('Please provide a CSV URL.', 'woo-stock-sync'),
            ]);
        }
        
        $result = WSSC()->sync->test_connection($url, $disable_ssl);
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }
    
    /**
     * Preview CSV columns
     */
    public function preview_csv() {
        $this->verify_nonce();
        
        // Validate license
        if (!WSSC()->license->is_valid()) {
            wp_send_json_error([
                'message' => __('Please activate a valid license first.', 'woo-stock-sync'),
            ]);
        }
        
        $url = isset($_POST['url']) ? esc_url_raw($_POST['url']) : '';
        $disable_ssl = isset($_POST['disable_ssl']) && $_POST['disable_ssl'] === 'true';
        
        if (empty($url)) {
            wp_send_json_error([
                'message' => __('Please provide a CSV URL.', 'woo-stock-sync'),
            ]);
        }
        
        $result = WSSC()->sync->preview_columns($url, $disable_ssl);
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }
    
    /**
     * Save settings
     */
    public function save_settings() {
        $this->verify_nonce();
        
        // Validate license
        if (!WSSC()->license->is_valid()) {
            wp_send_json_error([
                'message' => __('Please activate a valid license first.', 'woo-stock-sync'),
            ]);
        }
        
        // Get and sanitize settings
        $csv_url = isset($_POST['csv_url']) ? esc_url_raw($_POST['csv_url']) : '';
        $sku_column = isset($_POST['sku_column']) ? sanitize_text_field($_POST['sku_column']) : 'sku';
        $qty_column = isset($_POST['quantity_column']) ? sanitize_text_field($_POST['quantity_column']) : 'quantity';
        $interval = isset($_POST['schedule_interval']) ? sanitize_text_field($_POST['schedule_interval']) : 'hourly';
        $enabled = isset($_POST['enabled']) && $_POST['enabled'] === 'true';
        $disable_ssl = isset($_POST['disable_ssl']) && $_POST['disable_ssl'] === 'true';
        $missing_sku_action = isset($_POST['missing_sku_action']) ? sanitize_text_field($_POST['missing_sku_action']) : 'ignore';
        
        // Validate missing_sku_action
        $valid_actions = ['ignore', 'zero', 'private'];
        if (!in_array($missing_sku_action, $valid_actions, true)) {
            $missing_sku_action = 'ignore';
        }
        
        // Save settings
        update_option('wssc_csv_url', $csv_url);
        update_option('wssc_sku_column', $sku_column);
        update_option('wssc_quantity_column', $qty_column);
        update_option('wssc_schedule_interval', $interval);
        update_option('wssc_enabled', $enabled);
        update_option('wssc_disable_ssl', $disable_ssl);
        update_option('wssc_missing_sku_action', $missing_sku_action);
        
        // Handle scheduling
        if ($enabled && !empty($csv_url)) {
            WSSC()->scheduler->schedule($interval);
        } else {
            WSSC()->scheduler->unschedule();
        }
        
        wp_send_json_success([
            'message' => __('Settings saved successfully.', 'woo-stock-sync'),
        ]);
    }
    
    /**
     * Activate license
     */
    public function activate_license() {
        $this->verify_nonce();
        
        $license_key = isset($_POST['license_key']) ? sanitize_text_field($_POST['license_key']) : '';
        
        if (empty($license_key)) {
            wp_send_json_error([
                'message' => __('Please enter a license key.', 'woo-stock-sync'),
            ]);
        }
        
        $result = WSSC()->license->activate($license_key);
        
        if ($result['success']) {
            wp_send_json_success([
                'message' => __('License activated successfully!', 'woo-stock-sync'),
                'data' => $result['data'],
            ]);
        } else {
            // Provide user-friendly error messages for common cases
            $message = isset($result['message']) ? $result['message'] : __('Activation failed.', 'woo-stock-sync');
            
            // Check for domain limit error
            if (strpos($message, 'Domain limit') !== false || strpos($message, 'limit reached') !== false) {
                $message = __('Domain activation limit reached. Please deactivate another domain from your license dashboard first.', 'woo-stock-sync');
            }
            
            // Check for license not active error (403)
            if (strpos($message, 'not active') !== false || strpos($message, 'is not active') !== false) {
                $message = __('This license is not active. It may be expired, suspended, or cancelled.', 'woo-stock-sync');
            }
            
            wp_send_json_error([
                'message' => $message,
            ]);
        }
    }
    
    /**
     * Activate domain for existing license
     * Used when a license is valid but not activated on this domain
     */
    public function activate_domain() {
        $this->verify_nonce();
        
        $license_key = WSSC()->license->get_key();
        
        if (empty($license_key)) {
            wp_send_json_error([
                'message' => __('No license key found. Please enter a license key.', 'woo-stock-sync'),
            ]);
        }
        
        $result = WSSC()->license->activate($license_key);
        
        if ($result['success']) {
            wp_send_json_success([
                'message' => __('Domain activated successfully!', 'woo-stock-sync'),
                'data' => $result['data'],
            ]);
        } else {
            // Provide user-friendly error messages for common cases
            $message = isset($result['message']) ? $result['message'] : __('Activation failed.', 'woo-stock-sync');
            
            // Check for domain limit error
            if (strpos($message, 'Domain limit') !== false || strpos($message, 'limit reached') !== false) {
                $message = __('Domain activation limit reached. Please deactivate another domain from your license dashboard first.', 'woo-stock-sync');
            }
            
            wp_send_json_error([
                'message' => $message,
            ]);
        }
    }
    
    /**
     * Deactivate license
     */
    public function deactivate_license() {
        $this->verify_nonce();
        
        $result = WSSC()->license->deactivate();
        
        // Disable sync and clear restore flag
        update_option('wssc_enabled', false);
        delete_option('wssc_sync_disabled_by_license');
        WSSC()->scheduler->unschedule();
        
        if ($result['success']) {
            wp_send_json_success([
                'message' => __('License deactivated successfully.', 'woo-stock-sync'),
            ]);
        } else {
            // Still consider it success if we cleared local data
            wp_send_json_success([
                'message' => __('License deactivated locally.', 'woo-stock-sync'),
            ]);
        }
    }
    
    /**
     * Check/validate license status
     */
    public function check_license() {
        $this->verify_nonce();
        
        $result = WSSC()->license->validate();
        
        if ($result['success'] && isset($result['data'])) {
            wp_send_json_success([
                'valid' => !empty($result['data']['valid']),
                'activated' => !empty($result['data']['activated']),
                'data' => $result['data'],
            ]);
        } else {
            wp_send_json_error($result);
        }
    }
    
    /**
     * Clear logs
     */
    public function clear_logs() {
        $this->verify_nonce();
        
        WSSC()->logs->clear();
        
        wp_send_json_success([
            'message' => __('Logs cleared successfully.', 'woo-stock-sync'),
        ]);
    }
    
    /**
     * Get log details
     */
    public function get_log_details() {
        $this->verify_nonce();
        
        $log_id = isset($_POST['log_id']) ? intval($_POST['log_id']) : 0;
        
        if (!$log_id) {
            wp_send_json_error([
                'message' => __('Invalid log ID.', 'woo-stock-sync'),
            ]);
        }
        
        $log = WSSC()->logs->get_by_id($log_id);
        
        if (!$log) {
            wp_send_json_error([
                'message' => __('Log not found.', 'woo-stock-sync'),
            ]);
        }
        
        wp_send_json_success([
            'log' => $log,
        ]);
    }
    
    /**
     * Get sync status
     */
    public function get_sync_status() {
        $this->verify_nonce();
        
        $status = WSSC()->scheduler->get_status();
        $is_running = WSSC()->scheduler->is_running();
        
        wp_send_json_success([
            'status' => $status,
            'is_running' => $is_running,
        ]);
    }
    
    /**
     * Toggle sync enabled/disabled
     */
    public function toggle_sync() {
        $this->verify_nonce();
        
        // Validate license
        if (!WSSC()->license->is_valid()) {
            wp_send_json_error([
                'message' => __('Please activate a valid license first.', 'woo-stock-sync'),
            ]);
        }
        
        $enabled = isset($_POST['enabled']) && $_POST['enabled'] === 'true';
        
        update_option('wssc_enabled', $enabled);
        
        if ($enabled) {
            $csv_url = get_option('wssc_csv_url');
            if (empty($csv_url)) {
                update_option('wssc_enabled', false);
                wp_send_json_error([
                    'message' => __('Please configure a CSV URL in settings first.', 'woo-stock-sync'),
                ]);
            }
            
            WSSC()->scheduler->schedule();
            $message = __('Sync enabled successfully.', 'woo-stock-sync');
        } else {
            WSSC()->scheduler->unschedule();
            $message = __('Sync disabled.', 'woo-stock-sync');
        }
        
        wp_send_json_success([
            'message' => $message,
            'enabled' => $enabled,
        ]);
    }
    
    /**
     * Check for plugin updates
     */
    public function check_update() {
        $this->verify_nonce();
        
        // Force check for updates from GitHub
        WSSC()->updater->force_check();
        
        // Get fresh update data
        $update_data = get_transient('wssc_update_data');
        $current_version = WSSC_VERSION;
        $has_update = $update_data && !empty($update_data['version']) && version_compare($current_version, $update_data['version'], '<');
        
        if ($has_update) {
            wp_send_json_success([
                'message' => sprintf(
                    __('Update available! Version %s is ready to install.', 'woo-stock-sync'),
                    $update_data['version']
                ),
                'has_update' => true,
                'current_version' => $current_version,
                'new_version' => $update_data['version'],
                'download_url' => $update_data['download_url'],
            ]);
        } else {
            wp_send_json_success([
                'message' => __('You are running the latest version.', 'woo-stock-sync'),
                'has_update' => false,
                'current_version' => $current_version,
                'new_version' => $update_data['version'] ?? $current_version,
            ]);
        }
    }
    
    /**
     * Install plugin update
     */
    public function install_update() {
        $this->verify_nonce();
        
        // Check for update data
        $update_data = get_transient('wssc_update_data');
        
        if (!$update_data || empty($update_data['download_url'])) {
            wp_send_json_error([
                'message' => __('No update available or download URL missing.', 'woo-stock-sync'),
            ]);
        }
        
        // Include required files for plugin update
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        
        // Use a silent skin to prevent output
        $skin = new WP_Ajax_Upgrader_Skin();
        $upgrader = new Plugin_Upgrader($skin);
        
        // Deactivate the plugin before upgrading
        deactivate_plugins(WSSC_PLUGIN_BASENAME);
        
        // Clear the plugin from update cache to force fresh install
        $result = $upgrader->install($update_data['download_url'], [
            'overwrite_package' => true,
        ]);
        
        if (is_wp_error($result)) {
            // Reactivate plugin on failure
            activate_plugin(WSSC_PLUGIN_BASENAME);
            wp_send_json_error([
                'message' => $result->get_error_message(),
            ]);
        }
        
        if ($result === false) {
            // Reactivate plugin on failure
            activate_plugin(WSSC_PLUGIN_BASENAME);
            wp_send_json_error([
                'message' => __('Update failed. Please try again or update manually.', 'woo-stock-sync'),
            ]);
        }
        
        // Reactivate the plugin
        activate_plugin(WSSC_PLUGIN_BASENAME);
        
        // Clear update cache
        WSSC()->updater->clear_cache();
        delete_site_transient('update_plugins');
        
        wp_send_json_success([
            'message' => sprintf(
                __('Successfully updated to version %s. Please refresh the page.', 'woo-stock-sync'),
                $update_data['version']
            ),
            'new_version' => $update_data['version'],
            'reload' => true,
        ]);
    }
}
