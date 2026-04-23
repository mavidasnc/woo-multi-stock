<?php
/**
 * Class Total_Updater
 *
 * Aggregates per-warehouse stock meta fields into the native WooCommerce
 * `_stock` field via a two-phase AJAX batch process:
 *
 *  Phase 1 — wms_total_prepare
 *  ────────────────────────────
 *  Collects the IDs of every product and variation that has at least one meta
 *  key matching the pattern /^_stock_[A-Za-z0-9]+$/ (e.g. `_stock_CMT`,
 *  `_stock_WH2`). Stores the ID list in the transient `wms_total_ids` and the
 *  variation-only ID set in `wms_total_vars`, then returns the total count to JS.
 *
 *  Phase 2 — wms_total_batch
 *  ──────────────────────────
 *  For each product ID in the current batch, process_ids() reads ALL meta keys
 *  for that post and sums only those matching the warehouse pattern. The sum is
 *  then compared to the current `_stock` value:
 *   - If the sum matches and manage_stock does not need changing → SKIP (no DB write).
 *   - Otherwise write via wc_update_product_stock() or update_post_meta().
 *
 *  For product variations: if `manage_stock` is not already 'yes', it is set
 *  to 'yes' before writing stock so that WooCommerce can properly track stock
 *  status. A variation that needs manage_stock=yes is always written regardless
 *  of whether the sum has changed.
 *
 *  Single-product sync — wms_total_single
 *  ────────────────────────────────────────
 *  Re-runs the same aggregation for a single product/variation ID. Used by the
 *  per-row "Sync" button in the stock overview table.
 *
 * PUBLIC API FOR CLI/CRON
 * ───────────────────────
 * collect_ids() and process_ids() expose the core logic without any AJAX
 * coupling. The WP-CLI command calls collect_ids() once to get all IDs, then
 * loops process_ids() in batches. process_ids() now returns three counters:
 * 'processed', 'updated', and 'skipped'.
 *
 * @package WooMultiStock
 */

namespace WooMultiStock;

// Prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Total_Updater
 *
 * Sums all per-warehouse _stock_* metas and writes the total to WC _stock.
 */
class Total_Updater {

	/** @var string Transient key for the list of all product IDs to process. */
	private const TRANSIENT_KEY = 'wms_total_ids';

	/** @var string Transient key for the set of variation IDs (subset of TRANSIENT_KEY). */
	private const TRANSIENT_VARS_KEY = 'wms_total_vars';

	/** @var int Transient TTL in seconds (1 hour). */
	private const TRANSIENT_TTL = 3600;

	/** @var int Rows processed per AJAX request. */
	private const BATCH_SIZE = 50;

	/** @var string Regex that matches warehouse meta keys (e.g. _stock_CMT). */
	private const META_PATTERN = '/^_stock_[A-Za-z0-9]+$/';

	/** @var bool|null Cached WPML availability flag. */
	private static $wpml_available = null;

	// ── Public API ────────────────────────────────────────────────────────────

	/**
	 * Register WordPress AJAX hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'wp_ajax_wms_total_prepare', array( $this, 'handle_prepare' ) );
		add_action( 'wp_ajax_wms_total_batch',   array( $this, 'handle_process_batch' ) );
		add_action( 'wp_ajax_wms_total_single',  array( $this, 'handle_single' ) );
	}

	/**
	 * Collect all product/variation IDs that have at least one warehouse meta.
	 *
	 * WPML FILTER
	 * ───────────
	 * When WPML is active the query is joined against icl_translations and
	 * restricted to language_code = 'it'. This assumes WPML is configured to
	 * manage stock only from the Italian source products, avoiding double-writes
	 * on translated copies and cutting the ID list roughly in half.
	 *
	 * @return array {
	 *   @type int[] $ids           All product/variation IDs with warehouse metas.
	 *   @type int[] $variation_ids Subset of $ids whose post_type is product_variation.
	 * }
	 */
	public function collect_ids(): array {
		global $wpdb;

		$wpml_join = '';
		if ( self::is_wpml_available() ) {
			$wpml_join = "INNER JOIN {$wpdb->prefix}icl_translations tr
			              ON tr.element_id   = p.ID
			              AND tr.element_type IN ('post_product','post_product_variation')
			              AND tr.language_code = 'it'";
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			"SELECT DISTINCT pm.post_id, p.post_type
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 {$wpml_join}
			 WHERE pm.meta_key LIKE '_stock_%'
			   AND p.post_type IN ('product','product_variation')
			   AND p.post_status NOT IN ('trash','auto-draft')",
			ARRAY_A
		);
		// phpcs:enable

		$ids           = array();
		$variation_ids = array();

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$id    = (int) $row['post_id'];
				$ids[] = $id;

				if ( 'product_variation' === $row['post_type'] ) {
					$variation_ids[] = $id;
				}
			}
		}

		return array(
			'ids'           => array_values( array_unique( $ids ) ),
			'variation_ids' => array_values( array_unique( $variation_ids ) ),
		);
	}

	/**
	 * Process a list of product/variation IDs: sum warehouse metas → write WC _stock.
	 *
	 * SKIP OPTIMISATION
	 * ─────────────────
	 * If the computed sum already equals the current `_stock` value AND the
	 * variation's manage_stock flag does not need updating, the product is
	 * skipped (no DB write). This avoids unnecessary calls to
	 * wc_update_product_stock() — which triggers wc_delete_product_transients()
	 * on every call — for products whose stock has not changed since the last sync.
	 *
	 * @param int[] $ids           Post IDs to process in this call.
	 * @param array $variation_set array_flip( $variation_ids ) for O(1) lookup.
	 * @return array {
	 *   @type int $processed Number of IDs examined.
	 *   @type int $updated   Number of IDs whose stock was written.
	 *   @type int $skipped   Number of IDs whose stock was already up to date.
	 * }
	 */
	public function process_ids( array $ids, array $variation_set ): array {
		$processed = 0;
		$updated   = 0;
		$skipped   = 0;

		foreach ( $ids as $product_id ) {
			$sum     = $this->sum_warehouse_stocks( $product_id );
			$current = (int) get_post_meta( $product_id, '_stock', true );
			$manage  = get_post_meta( $product_id, '_manage_stock', true );
			$is_var  = isset( $variation_set[ $product_id ] );

			// For variations: ensure manage_stock=yes so WC tracks _stock_status.
			$needs_manage = $is_var && 'yes' !== $manage;

			// Skip DB write if sum is unchanged and no manage_stock fix needed.
			if ( ! $needs_manage && $current === $sum ) {
				$processed++;
				$skipped++;
				continue;
			}

			if ( $needs_manage ) {
				update_post_meta( $product_id, '_manage_stock', 'yes' );
				$manage = 'yes';
			}

			if ( 'yes' === $manage ) {
				wc_update_product_stock( $product_id, $sum );
			} else {
				update_post_meta( $product_id, '_stock', $sum );
			}

			$processed++;
			$updated++;
		}

		return array(
			'processed' => $processed,
			'updated'   => $updated,
			'skipped'   => $skipped,
		);
	}

	// ── AJAX handlers ─────────────────────────────────────────────────────────

	/**
	 * AJAX Phase 1: collect all product/variation IDs and store in transients.
	 *
	 * @return void
	 */
	public function handle_prepare(): void {
		check_ajax_referer( Admin::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				__( 'You do not have permission to perform this action.', 'woo-multi-stock' ),
				403
			);
		}

		$result = $this->collect_ids();

		set_transient( self::TRANSIENT_KEY,      $result['ids'],           self::TRANSIENT_TTL );
		set_transient( self::TRANSIENT_VARS_KEY, $result['variation_ids'], self::TRANSIENT_TTL );

		wp_send_json_success( array( 'total' => count( $result['ids'] ) ) );
	}

	/**
	 * AJAX Phase 2: process one batch — sum warehouse metas → write WC _stock.
	 *
	 * @return void
	 */
	public function handle_process_batch(): void {
		check_ajax_referer( Admin::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				__( 'You do not have permission to perform this action.', 'woo-multi-stock' ),
				403
			);
		}

		$offset = absint( isset( $_POST['offset'] ) ? $_POST['offset'] : 0 ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$all_ids = get_transient( self::TRANSIENT_KEY );

		if ( false === $all_ids || ! is_array( $all_ids ) ) {
			wp_send_json_error(
				__( 'Aggregation data has expired. Please click "Sync All" again.', 'woo-multi-stock' )
			);
			return;
		}

		$variation_ids = get_transient( self::TRANSIENT_VARS_KEY );
		$variation_set = is_array( $variation_ids ) ? array_flip( $variation_ids ) : array();

		$total = count( $all_ids );
		$batch = array_slice( $all_ids, $offset, self::BATCH_SIZE );

		$stats       = $this->process_ids( $batch, $variation_set );
		$next_offset = $offset + count( $batch );
		$is_done     = ( $next_offset >= $total );

		if ( $is_done ) {
			delete_transient( self::TRANSIENT_KEY );
			delete_transient( self::TRANSIENT_VARS_KEY );
		}

		wp_send_json_success(
			array(
				'processed'   => $stats['processed'],
				'updated'     => $stats['updated'],
				'skipped'     => $stats['skipped'],
				'next_offset' => $next_offset,
				'is_done'     => $is_done,
				'total'       => $total,
			)
		);
	}

	/**
	 * AJAX: aggregate warehouse metas for a single product and write WC _stock.
	 *
	 * Used by the per-row "Sync" button in the stock overview table.
	 * Reuses process_ids() with a single-element array — no transients needed.
	 *
	 * @return void
	 */
	public function handle_single(): void {
		check_ajax_referer( Admin::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				__( 'You do not have permission to perform this action.', 'woo-multi-stock' ),
				403
			);
		}

		$product_id = absint( isset( $_POST['product_id'] ) ? $_POST['product_id'] : 0 ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( ! $product_id ) {
			wp_send_json_error(
				__( 'No product ID specified.', 'woo-multi-stock' ),
				400
			);
		}

		$post_type     = get_post_type( $product_id );
		$variation_set = ( 'product_variation' === $post_type )
			? array( $product_id => 1 )
			: array();

		$stats    = $this->process_ids( array( $product_id ), $variation_set );
		$wc_stock = (int) get_post_meta( $product_id, '_stock', true );

		wp_send_json_success(
			array(
				'processed' => $stats['processed'],
				'updated'   => $stats['updated'],
				'skipped'   => $stats['skipped'],
				'wc_stock'  => $wc_stock,
			)
		);
	}

	// ── Private helpers ───────────────────────────────────────────────────────

	/**
	 * Detect whether WPML's translation table is present in this install.
	 *
	 * @return bool True when {prefix}icl_translations exists.
	 */
	private static function is_wpml_available(): bool {
		if ( null !== self::$wpml_available ) {
			return self::$wpml_available;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'icl_translations';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		self::$wpml_available = ( $found === $table );

		return self::$wpml_available;
	}

	/**
	 * Sum all warehouse stock meta values for a given product/variation.
	 *
	 * @param int $product_id  Post ID of the product or variation.
	 * @return int             Sum of all matching warehouse quantities (>= 0).
	 */
	private function sum_warehouse_stocks( int $product_id ): int {
		$all_meta = get_post_meta( $product_id );

		if ( ! is_array( $all_meta ) ) {
			return 0;
		}

		$sum = 0;

		foreach ( $all_meta as $key => $values ) {
			if ( ! preg_match( self::META_PATTERN, $key ) ) {
				continue;
			}
			$sum += (int) ( isset( $values[0] ) ? $values[0] : 0 );
		}

		return $sum;
	}
}
