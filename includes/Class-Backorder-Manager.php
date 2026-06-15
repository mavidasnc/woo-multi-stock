<?php
/**
 * Class-Backorder-Manager.php
 *
 * Forces backorder, manage-stock, and on-backorder stock status at runtime
 * via WooCommerce property filters — without writing anything to the database.
 * This approach survives any product update performed by an external
 * stock-management tool.
 *
 * Activated by the "Force backorders on all products" toggle in the plugin's
 * Warehouse Configuration section. When the toggle is off this class registers
 * no hooks at all (zero overhead on every request).
 *
 * With the forcing active every product is always purchasable in backorder, so
 * the native WooCommerce admin stock notifications (backorder / out-of-stock /
 * low-stock) become pure noise: this class also strips those actions from the
 * 'woocommerce_email_actions' list. The suppression is bound to the toggle —
 * turning the toggle off restores the native emails.
 *
 * Developer escape-hatch: hook into 'wms_force_backorders' to exclude specific
 * products without modifying this plugin:
 *
 *   add_filter( 'wms_force_backorders', function ( $force, $product ) {
 *       return 123 !== $product->get_id() ? $force : false;
 *   }, 10, 2 );
 *
 * @package WooMultiStock
 */

namespace WooMultiStock;

/**
 * Runtime backorder forcing via WooCommerce property filters.
 */
class Backorder_Manager {

	// ── Hook registration ─────────────────────────────────────────────────────

	/**
	 * Register WooCommerce property filters.
	 *
	 * Exits immediately (no hooks registered) when the global toggle is off.
	 * Must be called outside is_admin() so the filters apply on the frontend,
	 * during cart/checkout, and in WooCommerce REST API responses.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		// Zero overhead when the feature is disabled.
		if ( ! Warehouse_Manager::force_backorders() ) {
			return;
		}

		// ── Property overrides ────────────────────────────────────────────────
		// Priority 99: run after all other WooCommerce internal filters.
		// accepted_args = 2: WC passes ( $value, $product ) for every _get_* filter.

		add_filter( 'woocommerce_product_get_backorders',             array( $this, 'filter_backorders' ),          99, 2 );
		add_filter( 'woocommerce_product_variation_get_backorders',   array( $this, 'filter_backorders' ),          99, 2 );

		add_filter( 'woocommerce_product_get_manage_stock',           array( $this, 'filter_manage_stock' ),        99, 2 );
		add_filter( 'woocommerce_product_variation_get_manage_stock', array( $this, 'filter_manage_stock' ),        99, 2 );

		// Force stock_status → 'onbackorder' so that is_in_stock() returns true
		// even when an external tool has written 'outofstock' to the database.
		add_filter( 'woocommerce_product_get_stock_status',           array( $this, 'filter_stock_status' ),        99, 2 );
		add_filter( 'woocommerce_product_variation_get_stock_status', array( $this, 'filter_stock_status' ),        99, 2 );

		// Safety-net: override the computed booleans that WC derives from the
		// properties above (handles edge cases in cached / extended classes).
		// woocommerce_product_is_in_stock       → ( bool, WC_Product ) — 2 args.
		// woocommerce_product_backorders_allowed → ( bool, int id, WC_Product ) — 3 args.
		add_filter( 'woocommerce_product_is_in_stock',        array( $this, 'filter_is_in_stock' ),        99, 2 );
		add_filter( 'woocommerce_product_backorders_allowed', array( $this, 'filter_backorders_allowed' ),  99, 3 );

		// Rimuove le notifiche email di stock all'admin (backorder / esaurito / scorte
		// basse): con il forcing attivo ogni prodotto è sempre vendibile in backorder,
		// quindi queste notifiche native sono solo rumore. Attivo solo a forcing acceso.
		add_filter( 'woocommerce_email_actions', array( $this, 'filter_email_actions' ), 99 );
	}

	// ── Filter callbacks ──────────────────────────────────────────────────────

	/**
	 * Force backorders → 'yes'.
	 *
	 * @param mixed $value   Original meta value.
	 * @param mixed $product WC_Product or WC_Product_Variation.
	 * @return mixed  'yes' if forcing applies, original $value otherwise.
	 */
	public function filter_backorders( $value, $product ) {
		return $this->should_force( $product ) ? 'yes' : $value;
	}

	/**
	 * Force manage_stock → true.
	 *
	 * @param mixed $value   Original meta value.
	 * @param mixed $product WC_Product or WC_Product_Variation.
	 * @return mixed  true if forcing applies, original $value otherwise.
	 */
	public function filter_manage_stock( $value, $product ) {
		return $this->should_force( $product ) ? true : $value;
	}

	/**
	 * Force stock_status → 'onbackorder'.
	 *
	 * @param mixed $value   Original meta value.
	 * @param mixed $product WC_Product or WC_Product_Variation.
	 * @return mixed  'onbackorder' if forcing applies, original $value otherwise.
	 */
	public function filter_stock_status( $value, $product ) {
		return $this->should_force( $product ) ? 'onbackorder' : $value;
	}

	/**
	 * Force is_in_stock() → true (safety net).
	 *
	 * @param bool  $in_stock Computed in-stock boolean.
	 * @param mixed $product  WC_Product or WC_Product_Variation.
	 * @return bool
	 */
	public function filter_is_in_stock( $in_stock, $product ) {
		return $this->should_force( $product ) ? true : $in_stock;
	}

	/**
	 * Force backorders_allowed() → true (safety net).
	 *
	 * WooCommerce fires this filter with three arguments:
	 *   ( bool $allowed, int $product_id, WC_Product $product )
	 *
	 * @param bool  $allowed    Computed allowed boolean.
	 * @param int   $product_id Product post ID (unused, present for WC compat).
	 * @param mixed $product    WC_Product or WC_Product_Variation.
	 * @return bool
	 */
	public function filter_backorders_allowed( $allowed, $product_id, $product ) {
		return $this->should_force( $product ) ? true : $allowed;
	}

	/**
	 * Rimuove le action delle notifiche di stock all'admin dalla lista delle
	 * email transazionali di WooCommerce.
	 *
	 * @param array $actions Lista di action che innescano email transazionali.
	 * @return array          Lista ripulita dalle notifiche di stock.
	 */
	public function filter_email_actions( $actions ) {
		if ( ! $this->should_force( null ) ) {
			return $actions;
		}

		return array_values(
			array_diff(
				$actions,
				array(
					'woocommerce_product_on_backorder',
					'woocommerce_no_stock',
					'woocommerce_low_stock',
				)
			)
		);
	}

	// ── Private helpers ───────────────────────────────────────────────────────

	/**
	 * Decide whether forcing should apply to a given product.
	 *
	 * Exposes the 'wms_force_backorders' filter so developers can opt-out
	 * individual products (by ID, category, type, …) without touching this file.
	 *
	 * @param mixed $product WC_Product, WC_Product_Variation, or null.
	 * @return bool
	 */
	private function should_force( $product ): bool {
		return (bool) apply_filters( 'wms_force_backorders', true, $product );
	}
}
