<?php
/**
 * Plugin Name:       Woo Multi Stock
 * Plugin URI:        https://github.com/mavidasnc/woo-multi-stock
 * Description:       Synchronises warehouse stock quantities from remote CSV files to per-warehouse meta fields on WooCommerce products and variations. Supports multiple warehouses; aggregates totals into native WooCommerce stock.
 * Version:           1.7.1
 * Author:            Mavida s.n.c.
 * Author URI:        https://mavida.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       woo-multi-stock
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * WC requires at least: 7.0
 * WC tested up to:   9.9
 *
 * @package WooMultiStock
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * HOW IT WORKS (overview)
 * ─────────────────────────────────────────────────────────────────────────────
 * 1. An admin page (under WooCommerce) lets the user store a "Warehouse Name"
 *    and a "CSV Remote URL" in the WordPress options table.
 * 2. Clicking the "Start Sync" button triggers two sequential AJAX flows:
 *    a) Download & parse the CSV → cache rows in a WordPress transient.
 *    b) Process rows in batches of 50, updating `_stock_CMT` via
 *       update_post_meta() for each matched product/variation SKU.
 * 3. The UI shows a live progress bar and running counters; a summary is
 *    displayed when all batches are complete.
 * ─────────────────────────────────────────────────────────────────────────────
 */

// ── 1. Security: abort if accessed directly (not via WordPress) ──────────────
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── 2. PHP version guard ─────────────────────────────────────────────────────
// We require PHP 7.4 for typed properties and named arguments. If the server
// runs an older version, show an admin notice and deactivate gracefully so the
// user is not left with a broken site.
if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {

	add_action(
		'admin_notices',
		static function (): void {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: plugin name  2: required PHP version  3: current PHP version */
						__( '%1$s requires PHP %2$s or higher. You are running PHP %3$s. Please upgrade PHP or deactivate the plugin.', 'woo-multi-stock' ),
						'Woo Multi Stock',
						'7.4',
						PHP_VERSION
					)
				)
			);
		}
	);

	// Deactivate the plugin silently on the next admin_init tick.
	add_action(
		'admin_init',
		static function (): void {
			deactivate_plugins( plugin_basename( __FILE__ ) );
		}
	);

	// Stop loading this file — everything below assumes PHP ≥ 7.4.
	return;
}

// ── 3. Constants ─────────────────────────────────────────────────────────────
// Define once here so every class can reference them without magic strings.
// Using the WMS_ prefix (Woo Multi Stock) to avoid collisions with other plugins.

/** Plugin version — used for asset cache-busting. */
define( 'WMS_VERSION', '1.7.1' );

/** Absolute path to the plugin directory, with trailing slash. */
define( 'WMS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/** Public URL to the plugin directory, with trailing slash. */
define( 'WMS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Canonical reference to this file.
 * Used by register_activation_hook(), plugin_basename(), and HPOS declaration.
 */
define( 'WMS_PLUGIN_FILE', __FILE__ );

/** Text-domain string — matches "Text Domain:" header above. */
define( 'WMS_TEXT_DOMAIN', 'woo-multi-stock' );

// ── 4. PSR-4-style autoloader ────────────────────────────────────────────────
// Maps the WooMultiStock\ namespace to the includes/ directory.
//
// Naming convention (follows WordPress file-naming standard):
//   WooMultiStock\Admin         →  includes/Class-Admin.php
//   WooMultiStock\Processor     →  includes/Class-Processor.php
//   WooMultiStock\Stock_Updater →  includes/Class-Stock-Updater.php
//
// Transform algorithm:
//   1. Strip the namespace prefix "WooMultiStock\" from the FQCN.
//   2. Replace every underscore (_) with a hyphen (-).
//   3. Prepend "Class-".
//   4. Append ".php".
//   5. Resolve against WMS_PLUGIN_DIR . 'includes/'.
//
// Note: strncmp() is used instead of str_starts_with() to maintain PHP 7.4
// compatibility (str_starts_with was introduced in PHP 8.0).

spl_autoload_register(
	static function ( string $class ): void {
		// The namespace prefix this autoloader handles.
		$prefix     = 'WooMultiStock\\';
		$prefix_len = 14; // strlen( 'WooMultiStock\\' )

		// Bail out immediately if the class does not belong to our namespace.
		// This keeps the autoloader lean and avoids interfering with WordPress
		// core, WooCommerce, or other plugins.
		if ( 0 !== strncmp( $class, $prefix, $prefix_len ) ) {
			return;
		}

		// Strip the namespace prefix to get just the class "leaf" name.
		// e.g. "WooMultiStock\Stock_Updater" → "Stock_Updater"
		$relative = substr( $class, $prefix_len );

		// Build the filename:  "Stock_Updater" → "Class-Stock-Updater.php"
		$filename = 'Class-' . str_replace( '_', '-', $relative ) . '.php';

		// Build the full filesystem path.
		$full_path = WMS_PLUGIN_DIR . 'includes/' . $filename;

		// Require the file only if it actually exists; silently skip otherwise
		// so PHP's own "class not found" error surfaces naturally.
		if ( file_exists( $full_path ) ) {
			require_once $full_path;
		}
	}
);

// ── 5. HPOS (High-Performance Order Storage) compatibility declaration ────────
// WooCommerce 7.1+ offers a custom-tables-based order storage engine (HPOS).
// Declaring compatibility here prevents the "unknown compatibility" warning in
// WooCommerce → Status → Features. This plugin does not query orders directly,
// so we can truthfully declare full compatibility.
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				WMS_PLUGIN_FILE,
				true
			);
		}
	}
);

// ── 6. Bootstrap on plugins_loaded ───────────────────────────────────────────
// Priority 11 ensures WooCommerce (priority 10) is fully loaded before we
// attempt to use wc_get_product_id_by_sku() or any WC constant.
//
// We only bootstrap admin-facing classes here because:
// - CSV sync is a back-office operation (admin only).
// - There is nothing to load on the front end.
// - This avoids unnecessary class instantiation on every public request.
add_action(
	'plugins_loaded',
	static function (): void {

		// Guard: WooCommerce must be active. If not, show a clear notice and
		// do not attempt to load our classes (which depend on WC functions).
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					printf(
						'<div class="notice notice-error"><p>%s</p></div>',
						wp_kses(
							sprintf(
								/* translators: %s: WooCommerce plugin name in bold */
								__( '<strong>Woo Multi Stock</strong> requires %s to be installed and active.', 'woo-multi-stock' ),
								'<strong>WooCommerce</strong>'
							),
							array( 'strong' => array() )
						)
					);
				}
			);
			return;
		}

		// Load translated strings. The /languages sub-directory follows the
		// standard WordPress .po/.mo naming convention: woo-multi-stock-{locale}.mo
		load_plugin_textdomain(
			WMS_TEXT_DOMAIN,
			false,
			dirname( plugin_basename( WMS_PLUGIN_FILE ) ) . '/languages/'
		);

		// Migrate legacy single-warehouse options to the new multi-warehouse
		// format. Safe to call on every request — it is a no-op after the
		// first migration (checks for option existence before writing).
		$warehouse_manager = new \WooMultiStock\Warehouse_Manager();
		$warehouse_manager->maybe_migrate();

		// Backorder forcing — runtime WooCommerce property filters.
		// Registered outside is_admin() so they apply on the frontend, in cart /
		// checkout, and in REST API responses. The class self-checks the toggle
		// option and exits immediately when the feature is disabled (zero overhead).
		( new \WooMultiStock\Backorder_Manager() )->register_hooks();

			// GitHub self-updater: registrato fuori da is_admin() perché il
			// controllo aggiornamenti gira anche via WP-Cron (dove is_admin() è
			// false). La classe interroga l'API GitHub solo quando WordPress legge
			// il transient update_plugins, con caching, quindi zero overhead sulle
			// richieste normali.
			( new \WooMultiStock\Updater() )->register_hooks();

		// WP-CLI commands — registered outside is_admin() because WP-CLI returns
		// false for is_admin() in CLI context. The @when after_wp_load annotation
		// in the CLI class docblocks guarantees WooCommerce is available when any
		// command actually executes.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'wms', new \WooMultiStock\CLI() );
		}

		// Admin-only bootstrap: instantiate all classes and wire their hooks.
		// is_admin() returns true for both regular admin page requests and
		// wp-admin AJAX requests, which is exactly what we need.
		if ( is_admin() ) {
			// Class-Admin.php — settings page, UI rendering, asset enqueueing.
			( new \WooMultiStock\Admin() )->register_hooks();

			// Class-Processor.php — AJAX handlers for per-warehouse CSV download
			// and batch stock update. Must be registered on every admin request.
			( new \WooMultiStock\Processor() )->register_hooks();

			// Class-Warehouse-Manager.php — AJAX handler for saving warehouses.
			$warehouse_manager->register_hooks();

			// Class-Total-Updater.php — AJAX handlers for the Sync All operation
			// (sums all _stock_* metas → writes to WC _stock).
			( new \WooMultiStock\Total_Updater() )->register_hooks();

			// Class-Stock-Table.php — AJAX handler for the paginated stock table.
			( new \WooMultiStock\Stock_Table() )->register_hooks();
		}
	},
	11 // Priority 11: run after WooCommerce (priority 10).
);
