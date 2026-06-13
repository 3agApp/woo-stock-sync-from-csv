<?php
/**
 * Scheduler Class
 * 
 * Manages cron jobs for stock sync and watchdog monitoring.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WSSC_Scheduler {

    /**
     * MySQL named-lock identifier used to guarantee a single concurrent sync.
     */
    const LOCK_NAME = 'wssc_sync_lock';

    /**
     * wp_options row name used for the fallback lock when GET_LOCK is unavailable.
     */
    const OPTION_LOCK_NAME = 'wssc_sync_lock';

    /**
     * Available schedule intervals (includes custom + WordPress built-ins)
     */
    private $intervals = [];

    /**
     * How the currently held lock was acquired ('mysql', 'option', or null).
     */
    private $lock_method = null;
    
    /**
     * Constructor
     */
    public function __construct() {
        // Add custom cron intervals
        add_filter('cron_schedules', [$this, 'add_cron_intervals']);
        
        // Watchdog check
        add_action('wssc_watchdog_check', [$this, 'watchdog_check']);
        
        // Initialize intervals - combine custom intervals with WordPress built-ins
        // Use the shared definition from main plugin class for DRY
        $custom_intervals = Woo_Stock_Sync_From_CSV::get_custom_cron_intervals();
        
        // Add WordPress built-in intervals that we want to expose in the UI
        $builtin_intervals = [
            'hourly' => [
                'interval' => HOUR_IN_SECONDS,
                'display' => __('Hourly', 'woo-stock-sync'),
            ],
            'daily' => [
                'interval' => DAY_IN_SECONDS,
                'display' => __('Daily', 'woo-stock-sync'),
            ],
            'weekly' => [
                'interval' => WEEK_IN_SECONDS,
                'display' => __('Weekly', 'woo-stock-sync'),
            ],
        ];
        
        // Merge: custom first, then built-ins (order matters for UI)
        $this->intervals = array_merge(
            ['wssc_5min' => $custom_intervals['wssc_5min']],
            ['wssc_15min' => $custom_intervals['wssc_15min']],
            ['wssc_30min' => $custom_intervals['wssc_30min']],
            ['hourly' => $builtin_intervals['hourly']],
            ['wssc_2hours' => $custom_intervals['wssc_2hours']],
            ['wssc_4hours' => $custom_intervals['wssc_4hours']],
            ['wssc_6hours' => $custom_intervals['wssc_6hours']],
            ['wssc_12hours' => $custom_intervals['wssc_12hours']],
            ['daily' => $builtin_intervals['daily']],
            ['wssc_2days' => $custom_intervals['wssc_2days']],
            ['weekly' => $builtin_intervals['weekly']]
        );
    }
    
    /**
     * Add custom cron intervals to WordPress
     */
    public function add_cron_intervals($schedules) {
        // Get custom intervals from shared definition
        $custom_intervals = Woo_Stock_Sync_From_CSV::get_custom_cron_intervals();
        
        foreach ($custom_intervals as $key => $data) {
            if (!isset($schedules[$key])) {
                $schedules[$key] = $data;
            }
        }
        
        // Watchdog uses built-in 'hourly' schedule for maximum reliability
        
        return $schedules;
    }
    
    /**
     * Get available intervals
     */
    public function get_intervals() {
        return $this->intervals;
    }
    
    /**
     * Schedule sync
     * 
     * @param string $interval The interval key
     * @param bool $force Force reschedule even if within current interval
     */
    public function schedule($interval = null, $force = false) {
        if (!$interval) {
            $interval = get_option('wssc_schedule_interval', 'hourly');
        }
        
        $current_interval = get_option('wssc_schedule_interval', 'hourly');
        $next_scheduled = wp_next_scheduled('wssc_sync_event');
        $interval_seconds = $this->get_interval_seconds($interval);
        
        // If not forcing, check if we should keep the current schedule
        if (!$force && $next_scheduled) {
            $time_until_next = $next_scheduled - time();
            
            // If interval hasn't changed and next run is in the future and within the interval, keep it
            if ($interval === $current_interval && $time_until_next > 0 && $time_until_next <= $interval_seconds) {
                // Schedule is still valid, don't change it
                // Just ensure watchdog is scheduled
                if (!wp_next_scheduled('wssc_watchdog_check')) {
                    wp_schedule_event(time(), 'hourly', 'wssc_watchdog_check');
                }
                return true;
            }
            
            // If interval changed but next run is still within the NEW interval, keep it
            if ($interval !== $current_interval && $time_until_next > 0 && $time_until_next <= $interval_seconds) {
                // Just update the interval option, don't reschedule
                update_option('wssc_schedule_interval', $interval);
                if (!wp_next_scheduled('wssc_watchdog_check')) {
                    wp_schedule_event(time(), 'hourly', 'wssc_watchdog_check');
                }
                return true;
            }
        }
        
        // Clear existing schedule
        $this->unschedule();
        
        // Schedule new event
        wp_schedule_event(time() + $interval_seconds, $interval, 'wssc_sync_event');
        
        // Schedule watchdog if not exists
        if (!wp_next_scheduled('wssc_watchdog_check')) {
            wp_schedule_event(time(), 'hourly', 'wssc_watchdog_check');
        }
        
        update_option('wssc_schedule_interval', $interval);
        update_option('wssc_last_scheduled', time());
        
        return true;
    }
    
    /**
     * Unschedule sync
     */
    public function unschedule() {
        wp_clear_scheduled_hook('wssc_sync_event');
        return true;
    }
    
    /**
     * Reschedule sync (called after each sync)
     */
    public function reschedule() {
        $enabled = get_option('wssc_enabled', false);
        
        if (!$enabled) {
            return;
        }
        
        $interval = get_option('wssc_schedule_interval', 'hourly');
        
        // Clear and reschedule
        wp_clear_scheduled_hook('wssc_sync_event');
        wp_schedule_event(time() + $this->get_interval_seconds($interval), $interval, 'wssc_sync_event');
    }
    
    /**
     * Get interval in seconds
     */
    public function get_interval_seconds($interval_key) {
        $schedules = wp_get_schedules();
        
        if (isset($schedules[$interval_key])) {
            return $schedules[$interval_key]['interval'];
        }
        
        return HOUR_IN_SECONDS; // Default to hourly
    }
    
    /**
     * Get next scheduled run
     */
    public function get_next_run() {
        $timestamp = wp_next_scheduled('wssc_sync_event');
        return $timestamp ? $timestamp : null;
    }
    
    /**
     * Get time until next run
     */
    public function get_time_until_next_run() {
        $next = $this->get_next_run();
        
        if (!$next) {
            return null;
        }
        
        $diff = $next - time();
        
        if ($diff < 0) {
            return __('Overdue', 'woo-stock-sync');
        }
        
        return human_time_diff(time(), $next);
    }
    
    /**
     * Watchdog check
     * 
     * This runs every hour to ensure the sync cron is properly scheduled.
     * If sync is enabled but no cron is scheduled, it will reschedule it.
     */
    public function watchdog_check() {
        $enabled = get_option('wssc_enabled', false);
        
        if (!$enabled) {
            return;
        }
        
        // Check if license is valid
        if (!WSSC()->license->is_valid()) {
            // Log and disable
            WSSC()->logs->add([
                'type' => 'watchdog',
                'status' => 'warning',
                'message' => __('Watchdog: License invalid. Sync disabled.', 'woo-stock-sync'),
            ]);
            
            update_option('wssc_enabled', false);
            $this->unschedule();
            return;
        }
        
        // Check if sync cron is scheduled
        $next_run = wp_next_scheduled('wssc_sync_event');
        
        if (!$next_run) {
            // Cron is missing, reschedule it
            $interval = get_option('wssc_schedule_interval', 'hourly');
            wp_schedule_event(time(), $interval, 'wssc_sync_event');
            
            WSSC()->logs->add([
                'type' => 'watchdog',
                'status' => 'warning',
                'message' => __('Watchdog: Sync cron was missing. Rescheduled successfully.', 'woo-stock-sync'),
            ]);
            
            return;
        }
        
        // Check if cron is overdue by more than double the interval
        $interval = get_option('wssc_schedule_interval', 'hourly');
        $interval_seconds = $this->get_interval_seconds($interval);
        $overdue_threshold = $interval_seconds * 2;
        
        $time_diff = $next_run - time();
        
        // If it's been too long since last scheduled run
        if ($time_diff < -$overdue_threshold) {
            // Cron seems stuck, reschedule
            wp_clear_scheduled_hook('wssc_sync_event');
            wp_schedule_event(time(), $interval, 'wssc_sync_event');
            
            WSSC()->logs->add([
                'type' => 'watchdog',
                'status' => 'warning',
                'message' => __('Watchdog: Sync cron was stuck/overdue. Rescheduled successfully.', 'woo-stock-sync'),
            ]);
        }
        
        // Log successful watchdog check
        update_option('wssc_watchdog_last_check', time());
    }
    
    /**
     * Get sync status info
     */
    public function get_status() {
        $enabled = get_option('wssc_enabled', false);
        $interval = get_option('wssc_schedule_interval', 'hourly');
        $next_run = $this->get_next_run();
        $last_sync = get_option('wssc_last_sync_time');
        $watchdog_last = get_option('wssc_watchdog_last_check');
        
        $schedules = wp_get_schedules();
        $interval_display = isset($schedules[$interval]) ? $schedules[$interval]['display'] : $interval;
        
        // Calculate human-readable next run
        $next_run_human = null;
        if ($next_run) {
            $time_diff = $next_run - time();
            if ($time_diff < 0) {
                // Overdue - show how long overdue
                $next_run_human = sprintf(
                    __('Overdue by %s', 'woo-stock-sync'),
                    human_time_diff($next_run, time())
                );
            } else {
                $next_run_human = human_time_diff(time(), $next_run);
            }
        }
        
        return [
            'enabled' => $enabled,
            'interval' => $interval,
            'interval_display' => $interval_display,
            'next_run' => $next_run,
            'next_run_human' => $next_run_human,
            'next_run_formatted' => $next_run ? wp_date('Y-m-d H:i:s', $next_run) : null,
            'next_run_overdue' => $next_run && ($next_run < time()),
            'last_sync' => $last_sync,
            'last_sync_human' => $last_sync ? human_time_diff($last_sync, time()) . ' ' . __('ago', 'woo-stock-sync') : null,
            'watchdog_last' => $watchdog_last,
            'watchdog_last_human' => $watchdog_last ? human_time_diff($watchdog_last, time()) . ' ' . __('ago', 'woo-stock-sync') : null,
        ];
    }
    
    /**
     * Acquire the single-run sync lock.
     *
     * Primary mechanism is the MySQL named lock GET_LOCK(), which is scoped to the
     * current DB connection and is released automatically if the PHP process / DB
     * connection dies — so a killed sync never leaves a stale lock behind. When
     * GET_LOCK is unavailable (returns NULL on some clustered/managed hosts) we fall
     * back to an atomic wp_options row lock with stale takeover.
     *
     * @return bool True if the lock was acquired (caller MUST call release_lock()).
     */
    public function acquire_lock() {
        global $wpdb;

        // 0s timeout: do not wait, fail fast if another connection holds the lock.
        $result = $wpdb->get_var($wpdb->prepare("SELECT GET_LOCK(%s, 0)", self::LOCK_NAME));

        if ((string) $result === '1') {
            $this->lock_method = 'mysql';
            return true;
        }

        if ((string) $result === '0') {
            // Another connection holds the named lock — a sync is already running.
            return false;
        }

        // NULL => GET_LOCK errored / unsupported. Fall back to the option-row lock.
        return $this->acquire_option_lock();
    }

    /**
     * Fallback atomic lock using a wp_options row (unique option_name index).
     */
    private function acquire_option_lock() {
        global $wpdb;

        $now = time();

        // Atomic test-and-set: the unique key on option_name makes a duplicate INSERT fail.
        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
            self::OPTION_LOCK_NAME,
            (string) $now
        ));

        if ($inserted) {
            $this->lock_method = 'option';
            return true;
        }

        // A row already exists. Steal it only if the previous holder is clearly dead
        // (locked longer ago than a full runtime budget plus grace).
        $stolen = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = %s
             WHERE option_name = %s AND CAST(option_value AS UNSIGNED) < %d",
            (string) $now,
            self::OPTION_LOCK_NAME,
            $this->lock_stale_before()
        ));

        if ($stolen) {
            $this->lock_method = 'option';
            return true;
        }

        return false;
    }

    /**
     * Release the single-run sync lock held by this request.
     */
    public function release_lock() {
        global $wpdb;

        if ($this->lock_method === 'mysql') {
            $wpdb->get_var($wpdb->prepare("SELECT RELEASE_LOCK(%s)", self::LOCK_NAME));
        } elseif ($this->lock_method === 'option') {
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name = %s",
                self::OPTION_LOCK_NAME
            ));
        }

        $this->lock_method = null;
    }

    /**
     * Check if a sync is currently running (authoritative, lock-based).
     *
     * Reads the live lock state rather than a TTL transient, so it self-heals: a
     * crashed sync releases GET_LOCK automatically and a stale option-row lock is
     * ignored once it ages past the runtime budget.
     */
    public function is_running() {
        global $wpdb;

        // IS_USED_LOCK returns the connection id holding the named lock, or NULL.
        $used = $wpdb->get_var($wpdb->prepare("SELECT IS_USED_LOCK(%s)", self::LOCK_NAME));
        if ($used !== null) {
            return true;
        }

        // Fallback path: a non-stale option-row lock means a sync is in progress.
        $locked_at = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            self::OPTION_LOCK_NAME
        ));

        return $locked_at !== null && (int) $locked_at >= $this->lock_stale_before();
    }

    /**
     * Timestamp before which an option-row lock is considered stale (holder dead).
     */
    private function lock_stale_before() {
        $max_runtime = (int) apply_filters('wssc_max_runtime', WSSC_Sync::MAX_RUNTIME);
        return time() - ($max_runtime + 5 * MINUTE_IN_SECONDS);
    }
}
