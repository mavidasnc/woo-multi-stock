<?php
/**
 * Class Stock_Updater
 *
 * Single-responsibility class: given a SKU and a quantity, locate every
 * WooCommerce product (or variation) sharing that SKU and write the quantity
 * to a custom warehouse meta field (e.g. `_stock_CMT`, `_stock_WH2`).
 *
 * WPML COMPATIBILITY
 * ──────────────────
 * In a WPML/WooCommerce Multilingual install, a single SKU is shared across
 * translations (each language has its OWN post ID with the same `_sku` meta).
 * The old implementation used `wc_get_product_id_by_sku()`, which is filtered
 * by WCML to return only the product in the current admin language — so the
 * English translation never received updates.
 *
 * We now query `postmeta` directly (`SELECT post_id … WHERE meta_key='_sku'
 * AND meta_value=<sku>`) and update every matching post. This:
 *   1. Keeps all language copies in sync for the warehouse meta field.
 *   2. Still works when WPML is NOT installed (returns a single ID).
 *   3. Bypasses any WCML filtering of `wc_get_product_id_by_sku()`.
 *
 * BACKWARD COMPATIBILITY
 * ──────────────────────
 * `META_KEY = '_stock_CMT'` and the constructor's default meta key are
 * preserved. `update()` still returns `int|false` (first matched post ID or
 * false) so existing callers that check `false === $result` keep working.
 *
 * HOOK PROVIDED
 * ─────────────
 * woo_multi_stock_after_row_update (do_action) — fires ONCE PER POST updated,
 * with three args (product_id, sku, qty). In a WPML install with IT+EN copies
 * of the same SKU, the hook fires twice per CSV row.
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
 * Looks up every product matching a SKU and updates their warehouse meta field.
 */
class Stock_Updater {

	/**
	 * Default meta key — preserved for backward compatibility.
	 *
	 * @var string
	 */
	public const META_KEY = '_stock_CMT';

	/**
	 * The meta key this instance will write to.
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
	 * Update the warehouse meta field on every product matching the given SKU.
	 *
	 * Writes the same quantity to all posts that share the SKU (typically one
	 * per WPML language copy). The `woo_multi_stock_after_row_update` hook
	 * fires once per updated post.
	 *
	 * @param string $sku  The product SKU to look up.
	 * @param int    $qty  The stock quantity to write.
	 *
	 * @return int|false  First matched post ID on success (truthy for callers
	 *                    that only check success/failure). Boolean false if no
	 *                    product with the given SKU exists.
	 */
	public function update( string $sku, int $qty ) {

		$product_ids = $this->find_product_ids_by_sku( $sku );

		if ( empty( $product_ids ) ) {
			return false;
		}

		foreach ( $product_ids as $product_id ) {
			update_post_meta( $product_id, $this->meta_key, $qty );
			do_action( 'woo_multi_stock_after_row_update', $product_id, $sku, $qty );
		}

		// Return the first ID for backward compatibility — callers only need
		// a truthy value to distinguish "found" from "not found".
		return $product_ids[0];
	}

	/**
	 * Resolve a SKU to every product/variation ID that carries it.
	 *
	 * Direct postmeta query — intentionally bypasses wc_get_product_id_by_sku()
	 * so WPML/WCML filters don't scope the result to the current admin language.
	 *
	 * @param string $sku Product SKU.
	 * @return int[] Zero or more post IDs.
	 */
	private function find_product_ids_by_sku( string $sku ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT pm.post_id
				 FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key = '_sku'
				   AND pm.meta_value = %s
				   AND p.post_type IN ('product','product_variation')
				   AND p.post_status NOT IN ('trash','auto-draft')",
				$sku
			)
		);

		return array_map( 'intval', (array) $ids );
	}
}
