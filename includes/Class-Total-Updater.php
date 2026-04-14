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
 *  then written to WooCommerce stock:
 *   - If `manage_stock` = 'yes': wc_update_product_stock() (updates `_stock`
 *     and recalculates `_stock_status`).
 *   - Otherwise: update_post_meta($id, '_stock', $sum) (raw write; WC stock
 *     status is not managed for this product so no recalculation is needed).
 *  For product variations: if `manage_stock` is not already 'yes', it is set
 *  to 'yes' before writing stock so that WooCommerce can properly track stock
 *  status. Simple products and variable-product parents are left unchanged.
 *
 * PUBLIC API FOR CLI/CRON
 * ───────────────────────
 * collect_ids() and process_ids() expose the core logic without any AJAX
 * coupling (no transients, no $_POST, no wp_send_json_*). The WP-CLI command
 * calls collect_ids() once to get all IDs, then loops process_ids() in batches.
 * The AJAX handlers use the same methods but add transient-based state between
 * HTTP requests.
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

	// ── Public API ────────────────────────────────────────────────────────────

	/**
	 * Register WordPress AJAX hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'wp_ajax_wms_total_prepare', array( $this, 'handle_prepare' ) );
		add_action( 'wp_ajax_wms_total_batch',   array( $this, 'handle_process_batch' ) );
	}

	/**
	 * Collect all product/variation IDs that have at least one warehouse meta.
	 *
	 * Queries postmeta directly for performance — avoids loading WC product
	 * objects at this stage. Also separates variation IDs from the full set so
	 * process_ids() can enable manage_stock on variations without any per-item
	 * DB query.
	 *
	 * Called by handle_prepare() (which then stores results in transients) and
	 * directly by the WP-CLI sync-total command (which iterates without transients).
	 *
	 * @return array {
	 *   @type int[] $ids           All product/variation IDs with warehouse metas.
	 *   @type int[] $variation_ids Subset of $ids whose post_type is product_variation.
	 * }
	 */
	public function collect_ids(): array {
		global $wpdb;

		// Find all distinct post IDs that have at least one _stock_* warehouse meta.
		// Select post_type as well so we can split variations from simple products
		// in a single query — no extra lookups needed in process_ids().
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			"SELECT DISTINCT pm.post_id, p.post_type
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key LIKE '_stock_%'
			   AND p.post_type IN ('product','product_variation')
			   AND p.post_status NOT IN ('trash','auto-draft')",
			ARRAY_A
		);

		$ids           = array();
		$variation_ids = array();

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				// Accept the LIKE result and let process_ids() / sum_warehouse_stocks()
				// skip keys that don't match the strict warehouse pattern.
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
	 * For each ID:
	 *  1. Sum all _stock_* meta values via sum_warehouse_stocks().
	 *  2. If the ID is a variation and manage_stock is not yet 'yes', enable it
	 *     so WooCommerce can properly recalculate _stock_status.
	 *  3. Write the sum: wc_update_product_stock() when manage_stock='yes',
	 *     otherwise a raw update_post_meta() for unmanaged products.
	 *
	 * Called by handle_process_batch() with a single batch slice and by the
	 * WP-CLI sync-total command with arbitrary-sized slices.
	 *
	 * @param int[] $ids           Post IDs to process in this call.
	 * @param array $variation_set array_flip( $variation_ids ) for O(1) lookup.
	 *                             Keys are variation post IDs, values are ignored.
	 * @return array {
	 *   @type int $processed Number of IDs processed.
	 *   @type int $updated   Number of IDs whose stock was written.
	 * }
	 */
	public function process_ids( array $ids, array $variation_set ): array {
		$processed = 0;
		$updated   = 0;

		foreach ( $ids as $product_id ) {
			$sum    = $this->sum_warehouse_stocks( $product_id );
			$manage = get_post_meta( $product_id, '_manage_stock', true );

			// For variations: enable manage_stock so WC tracks _stock_status correctly.
			// Only write the meta when it is not already 'yes'; skip simple products
			// and variable-product parents (not present in $variation_set).
			if ( 'yes' !== $manage && isset( $variation_set[ $product_id ] ) ) {
				update_post_meta( $product_id, '_manage_stock', 'yes' );
				$manage = 'yes';
			}

			// Write to WooCommerce stock — use the proper WC API when manage_stock
			// is enabled so that _stock_status is recalculated correctly.
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
		);
	}

	// ── AJAX handlers ─────────────────────────────────────────────────────────

	/**
	 * AJAX Phase 1: collect all product/variation IDs and store in transients.
	 *
	 * Delegates to collect_ids() and stores both ID arrays in WordPress
	 * transients so handle_process_batch() can read them across HTTP requests.
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
	 * Reads the ID list from transients, slices the current batch by offset,
	 * and delegates the actual work to process_ids().
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

		// Build a lookup set for variation IDs collected during Phase 1.
		// array_flip() gives O(1) isset() checks instead of O(n) in_array().
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
