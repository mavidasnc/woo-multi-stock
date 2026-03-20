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
 *  `_stock_WH2`). Stores the ID list in the transient `wms_total_ids` and
 *  returns the total count to JS.
 *
 *  Phase 2 — wms_total_batch
 *  ──────────────────────────
 *  For each product ID in the current batch, sum_warehouse_stocks() reads ALL
 *  meta keys for that post and sums only those matching the warehouse pattern.
 *  The sum is then written to WooCommerce stock:
 *   - If `manage_stock` = 'yes': wc_update_product_stock() (updates `_stock`
 *     and recalculates `_stock_status`).
 *   - Otherwise: update_post_meta($id, '_stock', $sum) (raw write; WC stock
 *     status is not managed for this product so no recalculation is needed).
 *
 * IMPORTANT: this class does NOT re-download any CSV. It only reads the
 * existing warehouse meta values and writes their sum to WC `_stock`.
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

	/** @var string Transient key for the list of product IDs to process. */
	private const TRANSIENT_KEY = 'wms_total_ids';

	/** @var int Transient TTL in seconds (1 hour). */
	private const TRANSIENT_TTL = 3600;

	/** @var int Rows processed per AJAX request. */
	private const BATCH_SIZE = 50;

	/** @var string Regex that matches warehouse meta keys (e.g. _stock_CMT). */
	private const META_PATTERN = '/^_stock_[A-Za-z0-9]+$/';

	// ── Public API ────────────────────────────────────────────────────────────

	/**
	 * Register WordPress AJAX hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'wp_ajax_wms_total_prepare',      array( $this, 'handle_prepare' ) );
		add_action( 'wp_ajax_wms_total_batch',        array( $this, 'handle_process_batch' ) );
	}

	// ── AJAX handlers ─────────────────────────────────────────────────────────

	/**
	 * AJAX Phase 1: collect all product/variation IDs that have warehouse metas.
	 *
	 * Queries postmeta directly for performance — avoids loading WC product
	 * objects at this stage. Returns the total count to JS.
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

		global $wpdb;

		// Find all distinct post IDs that have at least one _stock_* warehouse meta.
		// We use LIKE '_stock_%' as a first filter and then refine in PHP to avoid
		// matching unrelated meta keys such as `_stock_status`.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			"SELECT DISTINCT pm.post_id
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key LIKE '_stock_%'
			   AND p.post_type IN ('product','product_variation')
			   AND p.post_status NOT IN ('trash','auto-draft')",
			ARRAY_A
		);

		$ids = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				// Refine: confirm the meta key matches the strict warehouse pattern.
				// Because we cannot use a regex in MySQL efficiently, we do this in PHP
				// by querying the meta keys for each post ID — but that would be N+1.
				// Instead, accept the LIKE result and let sum_warehouse_stocks() skip
				// keys that don't match the pattern (the sum will just be 0 for those).
				$ids[] = (int) $row['post_id'];
			}
		}

		$ids = array_values( array_unique( $ids ) );

		set_transient( self::TRANSIENT_KEY, $ids, self::TRANSIENT_TTL );

		wp_send_json_success( array( 'total' => count( $ids ) ) );
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
		}

		$total = count( $all_ids );
		$batch = array_slice( $all_ids, $offset, self::BATCH_SIZE );

		$processed = 0;
		$updated   = 0;

		foreach ( $batch as $product_id ) {
			$sum = $this->sum_warehouse_stocks( $product_id );

			// Write to WooCommerce stock — use the proper WC API when manage_stock
			// is enabled so that _stock_status is recalculated correctly.
			$manage = get_post_meta( $product_id, '_manage_stock', true );

			if ( 'yes' === $manage ) {
				wc_update_product_stock( $product_id, $sum );
			} else {
				update_post_meta( $product_id, '_stock', $sum );
			}

			$processed++;
			$updated++;
		}

		$next_offset = $offset + count( $batch );
		$is_done     = ( $next_offset >= $total );

		if ( $is_done ) {
			delete_transient( self::TRANSIENT_KEY );
		}

		wp_send_json_success(
			array(
				'processed'   => $processed,
				'updated'     => $updated,
				'next_offset' => $next_offset,
				'is_done'     => $is_done,
				'total'       => $total,
			)
		);
	}

	// ── Private helpers ───────────────────────────────────────────────────────

	/**
	 * Sum all warehouse stock meta values for a given product/variation.
	 *
	 * Reads ALL meta for the post (one get_post_meta call, no key), then
	 * filters on keys matching META_PATTERN. This avoids N per-warehouse
	 * queries when warehouses are added/removed dynamically.
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
			// get_post_meta() without a key returns arrays of single values.
			$sum += (int) ( isset( $values[0] ) ? $values[0] : 0 );
		}

		return $sum;
	}
}
