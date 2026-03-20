<?php
/**
 * Class Stock_Updater
 *
 * Single-responsibility class: given a SKU and a quantity, locate the
 * corresponding WooCommerce product (or variation) and write the quantity
 * to a custom warehouse meta field (e.g. `_stock_CMT`, `_stock_WH2`).
 *
 * BACKWARD COMPATIBILITY
 * ──────────────────────
 * The public constant META_KEY = '_stock_CMT' is preserved unchanged so
 * any external code that references Stock_Updater::META_KEY continues to
 * work without modification.
 *
 * When instantiated without arguments, `new Stock_Updater()` still writes
 * to `_stock_CMT`, exactly as before. Processor now passes the dynamic
 * meta key derived from Warehouse_Manager::get_meta_key() via the
 * constructor, e.g. `new Stock_Updater( '_stock_WH2' )`.
 *
 * IMPORTANT: NATIVE WOOCOMMERCE STOCK IS NOT MODIFIED
 * ────────────────────────────────────────────────────
 * This class deliberately does NOT call wc_update_product_stock() or modify
 * the _stock, _stock_status, or manage_stock meta fields. It writes ONLY to
 * the per-warehouse meta field. The Total_Updater class is responsible for
 * aggregating warehouse metas and writing the sum to WooCommerce's _stock.
 *
 * HOOK PROVIDED
 * ─────────────
 * woo_multi_stock_after_row_update (do_action) — fires after every successful
 * update_post_meta() call. Passes the product ID, SKU, and quantity so
 * external code can react (e.g. log, notify, sync to another system).
 * Consumers must declare add_action( ..., 10, 3 ).
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
 * Looks up a WooCommerce product by SKU and updates a warehouse meta field.
 */
class Stock_Updater {

	/**
	 * Default meta key — preserved for backward compatibility.
	 *
	 * External code can still reference Stock_Updater::META_KEY as before.
	 *
	 * @var string
	 */
	public const META_KEY = '_stock_CMT';

	/**
	 * The meta key this instance will write to.
	 *
	 * Set via the constructor; defaults to self::META_KEY so existing call
	 * sites that do `new Stock_Updater()` are entirely unaffected.
	 *
	 * @var string
	 */
	private $meta_key;

	/**
	 * Constructor.
	 *
	 * @param string $meta_key  Meta key to write. Defaults to '_stock_CMT'.
	 */
	public function __construct( string $meta_key = self::META_KEY ) {
		$this->meta_key = $meta_key;
	}

	/**
	 * Update the warehouse meta field for the product matching the given SKU.
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
	 * PHP 7.4 NOTE: The `int|false` union return type is PHP 8.0+ syntax.
	 * For PHP 7.4 compatibility, the return type hint is omitted from the
	 * method signature and documented only in the @return docblock tag.
	 *
	 * @param string $sku  The product SKU to look up (as read from the CSV).
	 * @param int    $qty  The stock quantity to write (already converted to int).
	 *
	 * @return int|false  Post ID of the updated product/variation on success.
	 *                    Boolean false if no product with the given SKU exists.
	 */
	public function update( string $sku, int $qty ) {

		// ── 1. Locate the product by SKU ──────────────────────────────────────
		$product_id = wc_get_product_id_by_sku( $sku );

		// ── 2. Guard: SKU not found ────────────────────────────────────────────
		if ( ! $product_id ) {
			return false;
		}

		// ── 3. Write the warehouse meta field ─────────────────────────────────
		// Uses $this->meta_key (set via constructor) instead of the hardcoded
		// META_KEY constant, enabling multi-warehouse support while remaining
		// 100% backward-compatible when called without constructor arguments.
		update_post_meta( $product_id, $this->meta_key, $qty );

		// ── 4. Hook: after each successful update ─────────────────────────────
		do_action( 'woo_multi_stock_after_row_update', $product_id, $sku, $qty );

		// ── 5. Return the product ID ──────────────────────────────────────────
		return $product_id;
	}
}
