<?php
/**
 * Class Stock_Updater
 *
 * Single-responsibility class: given a SKU and a quantity, locate the
 * corresponding WooCommerce product (or variation) and write the quantity
 * to the custom meta field `_stock_CMT`.
 *
 * WHY A SEPARATE CLASS?
 * ──────────────────────
 * Isolating the "find product + write meta" concern from the batch-processing
 * loop in Class-Processor.php keeps each class small, focused, and independently
 * testable. If in the future the lookup strategy changes (e.g. searching by a
 * different identifier, or writing to a different meta key), only this class
 * needs to change — Class-Processor.php remains untouched.
 *
 * IMPORTANT: NATIVE WOOCOMMERCE STOCK IS NOT MODIFIED
 * ────────────────────────────────────────────────────
 * This class deliberately does NOT call wc_update_product_stock() or modify
 * the _stock, _stock_status, or manage_stock meta fields. It writes ONLY to
 * `_stock_CMT`. This field is a custom warehouse-quantity reference that can
 * be read by other plugins, themes, or display logic — it does not affect
 * WooCommerce's own inventory management system.
 *
 * HOOK PROVIDED
 * ─────────────
 * woo_multi_stock_after_row_update (do_action) — fires after every successful
 * update_post_meta() call. Passes the product ID, SKU, and quantity so
 * external code can react (e.g. log, notify, sync to another system).
 *
 * @package WooMultiStock
 */

namespace WooMultiStock;

// Prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Stock_Updater
 *
 * Looks up a WooCommerce product by SKU and updates its _stock_CMT meta field.
 */
class Stock_Updater {

	/**
	 * The meta key written to the product post meta table.
	 *
	 * Using a constant here means there is a single authoritative source of
	 * truth for the meta key name. Any external code reading or querying this
	 * field can reference Stock_Updater::META_KEY instead of duplicating the
	 * string literal.
	 *
	 * The leading underscore marks this as a "protected" meta field in
	 * WordPress: it is hidden from the standard Custom Fields metabox in the
	 * post editor by default (unless explicitly shown).
	 *
	 * @var string
	 */
	public const META_KEY = '_stock_CMT';

	/**
	 * Update the _stock_CMT meta field for the product matching the given SKU.
	 *
	 * LOOKUP STRATEGY
	 * ───────────────
	 * wc_get_product_id_by_sku() queries the postmeta table for the `_sku`
	 * meta key. It returns the post ID of ANY product type — simple products,
	 * variable products, and individual variations all store their SKU in the
	 * same meta key. This means:
	 *
	 *  - If a simple product has SKU "ABC", its post ID is returned.
	 *  - If a variation has SKU "ABC", the variation's own post ID is returned
	 *    (NOT the parent variable product's ID).
	 *
	 * We write `_stock_CMT` to whichever post ID is returned, which is the
	 * correct behaviour for both product types.
	 *
	 * RETURN VALUE
	 * ────────────
	 * Returns the integer product/variation post ID on success, or boolean
	 * false when no product matches the SKU. This allows Class-Processor.php
	 * to distinguish between "found and updated" and "not found" without
	 * relying on exceptions or output buffering.
	 *
	 * PHP 7.4 NOTE: The `int|false` union return type is PHP 8.0+ syntax.
	 * For PHP 7.4 compatibility, the return type hint is omitted from the
	 * method signature and documented only in the @return docblock tag.
	 *
	 * @param string $sku  The product SKU to look up (as read from the CSV).
	 * @param int    $qty  The stock quantity to write (already converted to int
	 *                     by Class-Processor::parse_csv() before this call).
	 *
	 * @return int|false  Post ID of the updated product/variation on success.
	 *                    Boolean false if no product with the given SKU exists.
	 */
	public function update( string $sku, int $qty ) {

		// ── 1. Locate the product by SKU ──────────────────────────────────────
		// wc_get_product_id_by_sku() is the canonical WooCommerce function for
		// this lookup. It returns 0 (not false) when no match is found.
		// Internally it runs a cached postmeta query — repeated calls with the
		// same SKU in a single request hit the object cache, not the DB.
		$product_id = wc_get_product_id_by_sku( $sku );

		// ── 2. Guard: SKU not found ────────────────────────────────────────────
		// A return value of 0 means the SKU does not exist in the database.
		// We cast to bool (0 → false) so the caller receives a consistent
		// false rather than having to check for both 0 and false.
		if ( ! $product_id ) {
			return false;
		}

		// ── 3. Write the custom meta field ────────────────────────────────────
		// update_post_meta() is used rather than add_post_meta() because:
		//  - If the meta key already exists, update_post_meta() overwrites it.
		//  - If it does not exist yet, update_post_meta() creates it.
		// This idempotent behaviour means the sync is safe to run repeatedly.
		//
		// The quantity is stored as a plain integer — no string serialisation.
		// Reading it back: (int) get_post_meta( $id, '_stock_CMT', true )
		//
		// We intentionally do NOT use WC_Product::set_stock_quantity() or any
		// WooCommerce product save methods. Those methods trigger stock-status
		// recalculations, email notifications, and cache invalidations that
		// are undesirable for a background custom-meta update.
		update_post_meta( $product_id, self::META_KEY, $qty );

		// ── 4. Hook: after each successful update ─────────────────────────────
		// Fires after every successful update_post_meta() call.
		// External consumers can hook here to:
		//  - Log the update to a custom table or error log.
		//  - Trigger a webhook or API call to a third-party system.
		//  - Apply additional meta fields alongside _stock_CMT.
		//  - Calculate derived values (e.g. availability thresholds).
		//
		// The hook passes three parameters so consumers have full context
		// without needing to perform an additional DB lookup.
		//
		// Example usage in a theme's functions.php:
		//   add_action( 'woo_multi_stock_after_row_update', function( $id, $sku, $qty ) {
		//       update_post_meta( $id, '_stock_CMT_updated_at', current_time( 'mysql' ) );
		//   }, 10, 3 );
		//
		// Note the third argument to add_action() must be 3 to receive all
		// three parameters — the WordPress default is 1.
		do_action( 'woo_multi_stock_after_row_update', $product_id, $sku, $qty );

		// ── 5. Return the product ID ──────────────────────────────────────────
		// Returning the ID (rather than true) gives callers richer information:
		// they can verify which product was updated without an extra lookup.
		return $product_id;
	}
}
