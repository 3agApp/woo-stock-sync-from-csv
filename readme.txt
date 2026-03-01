=== Woo Stock Sync from CSV ===
Contributors: 3ag
Tags: woocommerce, stock, inventory, csv, sync, automation
Requires at least: 5.8
Tested up to: 6.4
Requires PHP: 7.4
WC requires at least: 5.0
WC tested up to: 8.0
Stable tag: 1.4.8
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically sync WooCommerce product stock from a CSV URL on a scheduled basis.

== Description ==

Woo Stock Sync from CSV is a premium WordPress plugin that automatically synchronizes your WooCommerce product stock levels from a CSV file hosted at any URL.

**Key Features:**

* **Automatic Sync** - Schedule stock updates from every 5 minutes to weekly
* **Large Catalog Support** - Optimized batch processing for 4000+ products
* **Custom Column Mapping** - Map any CSV column to SKU and quantity fields
* **Auto Delimiter Detection** - Supports comma, semicolon, tab, and pipe delimiters
* **Missing SKU Actions** - Choose what happens when products are not in CSV (ignore, set to 0, or make private)
* **Watchdog Cron** - Automatic recovery if scheduled sync stops working
* **Detailed Logs** - Complete history of all sync operations with statistics
* **Modern UI** - Clean, intuitive admin interface with real-time feedback
* **SSL Options** - Support for self-signed certificates
* **HPOS Compatible** - Works with WooCommerce High-Performance Order Storage

**Requirements:**

* WordPress 5.8 or higher
* WooCommerce 5.0 or higher
* PHP 7.4 or higher
* Valid license key from 3AG

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/woo-stock-sync-from-csv`
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Go to Stock Sync → License to activate your license key
4. Configure your CSV URL and column mappings in Stock Sync → Settings
5. Enable scheduled sync or run a manual sync from the Dashboard

== Frequently Asked Questions ==

= What CSV format is supported? =

The plugin supports standard CSV files with headers. Delimiters (comma, semicolon, tab, pipe) are automatically detected.

= How do I get a license key? =

Purchase a license at https://3ag.app/products/woo-stock-sync-from-csv

= Can I sync stock for variable products? =

Yes, the plugin supports both simple products and product variations. Each variation should have its own SKU in the CSV.

= What happens if a product SKU is not found in the CSV? =

You can configure this in Settings. Options include: ignore (keep current stock), set stock to 0, or make the product private (and restore when it returns).

== Changelog ==

= 1.4.8 =
* Version bump

= 1.4.7 =
* Fixed: Cron jobs not rescheduled after plugin auto-update (watchdog and sync stopped)
* Fixed: after_install not detecting plugin during auto-updates/bulk updates
* Added: Boot-time cron recovery on every page load as safety net

= 1.1.3 =
* Fixed: JavaScript error when viewing log details (duration.toFixed)

= 1.1.2 =
* Fixed: License now persists when plugin is deactivated/reactivated
* Fixed: License now persists when plugin is updated
* License deactivation only happens when user explicitly clicks "Deactivate License"

= 1.1.1 =
* Added manual "Check for Updates" button in License page
* Added one-click "Update" button when new version available
* Improved update UI with version info display

= 1.2.9 =
* Refactored: DRY - Cron intervals now defined once and shared across plugin
* Refactored: DRY - Stats array defined once via get_default_stats() method
* Fixed: Deprecated current_time('timestamp') replaced with time()
* Fixed: Missing wssc_watchdog_last_check option in uninstall cleanup
* Fixed: sanitize_sql_orderby() now handles false return value safely
* Fixed: Watchdog docblock now correctly says 'hourly' not '4 hours'
* Removed: Unused get_products_by_skus() dead code
* Code quality improvements from senior engineer review

= 1.2.8 =
* Fixed: Cron intervals now registered during activation (prevents scheduling failure)
* Fixed: Scheduled sync properly resumes after deactivate/reactivate cycle
* Fixed: "Next run" now displays correctly after plugin reactivation
* Root cause: Custom cron schedules weren't available during activation hook

= 1.2.7 =
* Fixed: Scheduled sync now resumes immediately after plugin reactivation
* Fixed: "Next run" no longer empty after deactivate/reactivate cycle

= 1.2.6 =
* Fixed: Uninstall.php now correctly cleans up all plugin options and cron hooks
* Fixed: Scheduled sync now has lock to prevent concurrent runs
* Fixed: SQL injection prevention using $wpdb->prepare for table existence checks
* Fixed: Privatized products tracking now cleans up orphaned entries
* Improved: Logs table uses composite index for better query performance
* Security: Enhanced database query safety throughout

= 1.2.5 =
* Fixed: Saving settings no longer resets next scheduled run if within current interval
* Fixed: Next run time now shows "Overdue by X" when scheduled time has passed
* Improved: Smart scheduling - only reschedules when necessary
* Added: Visual indicator (red text) for overdue scheduled syncs

= 1.2.4 =
* Fixed: Catalog visibility now set to 'visible' (Shop and search results) when restoring private products to public
* Note: When making products private, catalog visibility is already set to 'hidden'

= 1.2.3 =
* Fixed: Bug where option value 'private' was compared against 'set_private' - products now correctly restored to publish

= 1.2.2 =
* Fixed: Products now correctly restored to 'publish' status when SKU returns to CSV
* Added: "Restored" stat displayed in log details when private products are made public
* Improved: Log modal now shows Set Private and Restored statistics

= 1.2.1 =
* Fixed: Log timestamps now stored in UTC for consistent timezone conversion
* Fixed: Modal date display now shows correct local time regardless of viewer's timezone

= 1.2.0 =
* Fixed: Negative "ago" times (-3585 sec ago) caused by UTC/local timezone mismatch
* Fixed: Database timestamps now correctly interpreted as UTC
* Fixed: JavaScript handles negative time differences gracefully
* Improved: Times under 5 seconds show "just now" instead of "0 sec ago"

= 1.1.9 =
* Fixed: Incorrect "ago" times (e.g., showing 58 min instead of 2 min)
* Fixed: human_time_diff() now uses proper second parameter
* Added: All timestamps now display in user's local timezone via JavaScript
* Added: Hover tooltip shows full local date/time

= 1.1.8 =
* Performance: Major sync optimization - 10-15x faster for large catalogs
* Performance: Direct SQL updates instead of WC product save loops
* Performance: Batch SKU lookups with stock values in single query
* Performance: Database transactions for faster writes
* Performance: Optimized cache clearing per-batch instead of per-product

= 1.1.7 =
* Added: Auto-updates enabled by default

= 1.1.6 =
* Fixed: Fatal error - maybe_create_table method name mismatch in logs class

= 1.1.5 =
* Changed: Updates now fetched from GitHub Releases instead of 3AG API
* Changed: Updates available to all users (no license required for updates)
* Improved: Changelog pulled from GitHub release notes
* Removed: 3AG Update API dependency

= 1.1.4 =
* Fixed: Stats not resetting between sync runs
* Fixed: Added license validation to test connection and preview
* Fixed: WP_Filesystem initialization in auto-updater
* Fixed: XSS vulnerability in log modal JavaScript
* Fixed: Replaced deprecated current_time('timestamp')
* Added: Rate limiting for manual sync (30 second cooldown)
* Added: Database version handling for future schema upgrades
* Added: Proper uninstall cleanup (options, transients, cron, table)
* Added: Input validation for missing_sku_action setting
* Improved: Centralized domain helper function
* Improved: Code quality and industry standards compliance

= 1.1.0 =
* Added automatic updates via 3AG Update API
* Plugin now checks for updates and notifies in WordPress dashboard
* Seamless one-click updates with valid license

= 1.0.2 =
* Added static download URL for latest release

= 1.0.1 =
* Fixed missing SKU action feature
* Added catalog visibility control when setting products to private
* Improved product restoration when SKU returns in CSV

= 1.0.0 =
* Initial release
* Automatic stock sync from CSV URL
* Scheduled sync with configurable intervals
* Manual sync trigger
* Custom SKU and quantity column mapping
* Auto delimiter detection
* Missing SKU action settings
* Watchdog cron for reliability
* Comprehensive logging system
* Modern admin interface
* License management via 3AG API
* HPOS compatibility

== Upgrade Notice ==

= 1.0.0 =
Initial release of Woo Stock Sync from CSV.
