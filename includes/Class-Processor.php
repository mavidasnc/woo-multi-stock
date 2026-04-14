<?php
/**
 * Class Processor
 *
 * Handles the two core AJAX actions that power the stock sync process:
 *
 *  1. woo_multi_stock_download  — Downloads the remote CSV file via wp_remote_get(),
 *     parses every row, converts the quantity format, and caches the resulting
 *     data array in a WordPress transient. Returns the total row count to JS.
 *
 *  2. woo_multi_stock_process_batch — Reads the cached rows from the transient,
 *     slices out the current batch (determined by the JS-supplied offset), and
 *     delegates each row to Stock_Updater::update(). Returns cumulative counters
 *     and state flags to drive the JS progress bar.
 *
 * MULTI-WAREHOUSE SUPPORT
 * ───────────────────────
 * Both handlers now require a `warehouse_id` POST parameter. This ID is used to
 * look up the corresponding warehouse via Warehouse_Manager::get_by_id(), which
 * in turn provides the CSV URL, the dynamic transient key (wms_csv_{id}), and
 * the meta key (_stock_{LABEL}).
 *
 * PUBLIC API FOR CLI/CRON
 * ───────────────────────
 * fetch_rows( $warehouse ) downloads and parses the CSV without touching any
 * transient. CLI commands call this directly and iterate over all rows in one
 * PHP execution, bypassing the AJAX batch loop. AJAX handlers still use the
 * transient approach so the browser can display incremental progress.
 *
 * STATE BETWEEN REQUESTS
 * ──────────────────────
 * The CSV rows are stored in a per-warehouse WordPress transient after the first
 * (download) request. Every subsequent process_batch request reads from that
 * same transient. No PHP session, no temp file, no custom table.
 * The JS tracks the current position by passing `offset` as a POST parameter,
 * incrementing it by the count of rows returned per batch.
 *
 * HOOKS PROVIDED
 * ──────────────
 *  - woo_multi_stock_before_processing  (do_action)    — fired at the top of each
 *    batch before any update_post_meta() calls.
 *  - woo_multi_stock_after_row_update   (do_action)    — fired by Stock_Updater
 *    after each successful meta update (see Class-Stock-Updater.php).
 *  - woo_multi_stock_download_timeout   (apply_filters) — allows overriding the
 *    HTTP timeout used in wp_remote_get().
 *
 * @package WooMultiStock
 */

namespace WooMultiStock;

// Prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Processor
 *
 * Registers and handles AJAX actions for CSV download and batch stock update.
 */
class Processor {

	// ── Transient configuration ───────────────────────────────────────────────

	/**
	 * Transient lifetime in seconds.
	 *
	 * 3 600 s = 1 hour. Generous enough to cover a slow bulk sync of tens of
	 * thousands of rows (at 50 rows/request × 500 ms/request = ~100 s for
	 * 10 000 rows). If the sync takes longer than this, the user must re-click
	 * "Start Sync" to re-download the CSV.
	 *
	 * @var int
	 */
	private const TRANSIENT_TTL = 3600;

	/**
	 * Number of CSV rows processed per AJAX request.
	 *
	 * 50 is a safe default: large enough to keep the total request count low,
	 * small enough to avoid hitting PHP max_execution_time on most shared hosts.
	 * This value is also passed to JS via wmsData.batchSize so the progress
	 * bar can display accurate steps even before the first request completes.
	 *
	 * @var int
	 */
	private const BATCH_SIZE = 50;

	// ── Public API ────────────────────────────────────────────────────────────

	/**
	 * Register WordPress AJAX hooks for both actions.
	 *
	 * Only wp_ajax_ (authenticated) hooks are registered — no wp_ajax_nopriv_.
	 * This restricts the sync to logged-in users. An additional capability
	 * check (manage_options) inside each handler adds a second layer of
	 * security beyond the nonce.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'wp_ajax_woo_multi_stock_download',      array( $this, 'handle_download' ) );
		add_action( 'wp_ajax_woo_multi_stock_process_batch', array( $this, 'handle_process_batch' ) );
	}

	/**
	 * Download and parse the CSV for a warehouse without caching the result.
	 *
	 * This method contains the pure HTTP + parsing logic extracted from
	 * handle_download() so that both the AJAX handler and the WP-CLI command
	 * can reuse it without duplicating code.
	 *
	 * The AJAX handler calls fetch_rows() and then stores the result in a
	 * transient so subsequent batch requests can read it. The CLI command calls
	 * fetch_rows() and iterates over all rows directly in one PHP execution.
	 *
	 * @param array $warehouse Warehouse map: ['id' => string, 'label' => string, 'csv_url' => string].
	 * @return array|\WP_Error Parsed rows as [['sku' => string, 'qty' => int], ...],
	 *                         or a WP_Error on any failure.
	 */
	public function fetch_rows( array $warehouse ) {
		$csv_url = esc_url_raw( (string) ( isset( $warehouse['csv_url'] ) ? $warehouse['csv_url'] : '' ) );

		if ( empty( $csv_url ) ) {
			return new \WP_Error(
				'wms_no_url',
				__( 'No CSV URL is configured. Please add a URL in the plugin settings and save.', 'woo-multi-stock' )
			);
		}

		// ── HTTP request ──────────────────────────────────────────────────────
		$timeout = (int) apply_filters( 'woo_multi_stock_download_timeout', 60 );

		$response = wp_remote_get(
			$csv_url,
			array(
				'timeout'    => $timeout,
				'user-agent' => 'WooMultiStock/' . WMS_VERSION . '; WordPress/' . get_bloginfo( 'version' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'wms_http_error',
				sprintf(
					/* translators: %s: WP_Error message */
					__( 'Could not reach the CSV URL: %s', 'woo-multi-stock' ),
					$response->get_error_message()
				)
			);
		}

		$http_code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $http_code ) {
			return new \WP_Error(
				'wms_http_status',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'The CSV server returned HTTP %d. Please check the URL and server configuration.', 'woo-multi-stock' ),
					$http_code
				)
			);
		}

		// ── Parse ─────────────────────────────────────────────────────────────
		$body = wp_remote_retrieve_body( $response );

		if ( empty( trim( $body ) ) ) {
			return new \WP_Error(
				'wms_empty_body',
				__( 'The CSV file is empty. Nothing to process.', 'woo-multi-stock' )
			);
		}

		// Guard: detect HTML page returned instead of CSV data.
		$body_start = ltrim( substr( $body, 0, 100 ) );
		if ( preg_match( '/^<!(?:DOCTYPE|doctype)\s/i', $body_start )
			|| preg_match( '/^<html[\s>]/i', $body_start ) ) {
			return new \WP_Error(
				'wms_html_response',
				__( 'The URL returned an HTML page instead of CSV data. If you are using Google Drive, replace the share/view link with the direct download URL: drive.google.com/uc?export=download&id=FILE_ID', 'woo-multi-stock' )
			);
		}

		$rows = $this->parse_csv( $body );

		if ( empty( $rows ) ) {
			return new \WP_Error(
				'wms_no_rows',
				__( 'No valid rows found in the CSV after parsing. Check the file format (delimiter: ;).', 'woo-multi-stock' )
			);
		}

		return $rows;
	}

	// ── AJAX handlers (public so WordPress can call them) ─────────────────────

	/**
	 * AJAX handler: download and cache the remote CSV for a specific warehouse.
	 *
	 * Delegates HTTP + parsing to fetch_rows() and stores the result in a
	 * per-warehouse transient for subsequent process_batch calls.
	 *
	 * @return void  (terminates via wp_send_json_* — never returns normally)
	 */
	public function handle_download(): void {
		// ── Security ──────────────────────────────────────────────────────────
		check_ajax_referer( Admin::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				__( 'You do not have permission to perform this action.', 'woo-multi-stock' ),
				403
			);
		}

		// ── Resolve warehouse ──────────────────────────────────────────────────
		$warehouse     = $this->get_warehouse_or_die();
		$transient_key = Warehouse_Manager::get_transient_key( $warehouse );

		// ── Download + parse via shared method ────────────────────────────────
		$rows = $this->fetch_rows( $warehouse );

		if ( is_wp_error( $rows ) ) {
			wp_send_json_error( $rows->get_error_message() );
		}

		// ── Cache rows in warehouse-specific transient ────────────────────────
		set_transient( $transient_key, $rows, self::TRANSIENT_TTL );

		wp_send_json_success( array( 'total' => count( $rows ) ) );
	}

	/**
	 * AJAX handler: process one batch of CSV rows for a specific warehouse.
	 *
	 * Flow:
	 *  1. Verify nonce + capability.
	 *  2. Resolve the warehouse from the POST `warehouse_id` param.
	 *  3. Read offset from POST.
	 *  4. Load the cached rows from the warehouse-specific transient.
	 *  5. Fire woo_multi_stock_before_processing hook.
	 *  6. Slice the batch: rows[offset .. offset + BATCH_SIZE - 1].
	 *  7. For each row, call Stock_Updater::update() and track results.
	 *  8. Return JSON with cumulative counters, next offset, and is_done flag.
	 *
	 * @return void  (terminates via wp_send_json_* — never returns normally)
	 */
	public function handle_process_batch(): void {
		// ── Security ──────────────────────────────────────────────────────────
		check_ajax_referer( Admin::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				__( 'You do not have permission to perform this action.', 'woo-multi-stock' ),
				403
			);
		}

		// ── Resolve warehouse ──────────────────────────────────────────────────
		$warehouse     = $this->get_warehouse_or_die();
		$transient_key = Warehouse_Manager::get_transient_key( $warehouse );
		$meta_key      = Warehouse_Manager::get_meta_key( $warehouse );

		// ── Read and sanitise the offset ──────────────────────────────────────
		$offset = absint( isset( $_POST['offset'] ) ? $_POST['offset'] : 0 ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		// ── Load cached rows ──────────────────────────────────────────────────
		$all_rows = get_transient( $transient_key );

		if ( false === $all_rows || ! is_array( $all_rows ) ) {
			wp_send_json_error(
				__( 'CSV data has expired or is missing. Please click "Start Sync" again to re-download the file.', 'woo-multi-stock' )
			);
		}

		$total = count( $all_rows );

		// ── Hook: before processing ───────────────────────────────────────────
		do_action( 'woo_multi_stock_before_processing', $total );

		// ── Slice the batch ───────────────────────────────────────────────────
		$batch = array_slice( $all_rows, $offset, self::BATCH_SIZE );

		// ── Process each row ──────────────────────────────────────────────────
		$updater   = new Stock_Updater( $meta_key );
		$processed = 0;
		$not_found = 0;
		$updated   = 0;

		foreach ( $batch as $row ) {
			$result = $updater->update( $row['sku'], $row['qty'] );

			$processed++;

			if ( false === $result ) {
				$not_found++;
			} else {
				$updated++;
			}
		}

		// ── Calculate next state ──────────────────────────────────────────────
		$next_offset = $offset + count( $batch );
		$is_done     = ( $next_offset >= $total );

		if ( $is_done ) {
			delete_transient( $transient_key );
		}

		wp_send_json_success(
			array(
				'processed'   => $processed,
				'not_found'   => $not_found,
				'updated'     => $updated,
				'next_offset' => $next_offset,
				'is_done'     => $is_done,
				'total'       => $total,
			)
		);
	}

	// ── Private helpers ───────────────────────────────────────────────────────

	/**
	 * Read `warehouse_id` from POST, look it up via Warehouse_Manager, and return
	 * the warehouse array. Calls wp_send_json_error() + exits if missing/unknown.
	 *
	 * @return array  The warehouse map ['id', 'label', 'csv_url'].
	 */
	private function get_warehouse_or_die(): array {
		$id = isset( $_POST['warehouse_id'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			? sanitize_title( wp_unslash( (string) $_POST['warehouse_id'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			: '';

		if ( '' === $id ) {
			wp_send_json_error(
				__( 'No warehouse specified.', 'woo-multi-stock' ),
				400
			);
		}

		$wm        = new Warehouse_Manager();
		$warehouse = $wm->get_by_id( $id );

		if ( false === $warehouse ) {
			wp_send_json_error(
				__( 'Unknown warehouse ID.', 'woo-multi-stock' ),
				400
			);
		}

		return $warehouse;
	}

	/**
	 * Parse the raw CSV body string into a structured PHP array.
	 *
	 * CSV format:
	 *  - Encoding: UTF-8, with or without BOM (\xEF\xBB\xBF) — BOM is stripped.
	 *  - Delimiter: semicolon (;)
	 *  - Line endings: \r\n (Windows), \r (old Mac), or \n (Unix) — all accepted.
	 *  - First row: header — ALWAYS skipped (array_shift on lines).
	 *  - Data rows: SKU;QUANTITY  e.g.  01.02.0317;1,0000000000000
	 *
	 * Quantity conversion:
	 *  - The quantity uses a comma as the decimal separator (European format).
	 *  - Replace comma with dot → cast to float → cast to int.
	 *  - "1,0000000000000" → "1.0000000000000" → 1.0 → 1
	 *
	 * Visibility is protected (not private) so that fetch_rows() — and any
	 * subclass that needs to override parsing — can call this method directly.
	 *
	 * @param string $body  Raw HTTP response body (full CSV file content).
	 * @return array        Array of [ 'sku' => string, 'qty' => int ] maps.
	 */
	protected function parse_csv( string $body ): array {
		// Strip UTF-8 BOM.
		if ( "\xEF\xBB\xBF" === substr( $body, 0, 3 ) ) {
			$body = substr( $body, 3 );
		}

		$lines = preg_split( '/\r\n|\r|\n/', trim( $body ) );

		if ( empty( $lines ) ) {
			return array();
		}

		// Skip the header row.
		array_shift( $lines );

		$rows = array();

		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}

			$parts = str_getcsv( $line, ';' );

			if ( count( $parts ) < 2 ) {
				continue;
			}

			$sku     = trim( $parts[0] );
			$qty_raw = trim( $parts[1] );

			if ( '' === $sku ) {
				continue;
			}

			$qty = (int) (float) str_replace( ',', '.', $qty_raw );

			$rows[] = array(
				'sku' => $sku,
				'qty' => $qty,
			);
		}

		return $rows;
	}
}
