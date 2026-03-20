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
 * STATE BETWEEN REQUESTS
 * ──────────────────────
 * The CSV rows are stored in a WordPress transient after the first (download)
 * request. Every subsequent process_batch request reads from that same transient.
 * No PHP session, no temp file, no custom table. The JS tracks the current
 * position by passing `offset` as a POST parameter, incrementing it by the
 * count of rows returned per batch.
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
	 * Transient key under which the parsed CSV rows are stored.
	 *
	 * The transient holds a plain PHP array (WordPress serialises it
	 * automatically). Shape:
	 *   [ ['sku' => '01.02.0317', 'qty' => 1], ... ]
	 *
	 * @var string
	 */
	private const TRANSIENT_KEY = 'woo_multi_stock_csv_rows';

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

	// ── AJAX handlers (public so WordPress can call them) ─────────────────────

	/**
	 * AJAX handler: download and cache the remote CSV.
	 *
	 * Flow:
	 *  1. Verify nonce + capability.
	 *  2. Read the stored CSV URL from options; bail if empty.
	 *  3. Fetch the file with wp_remote_get() (configurable timeout via filter).
	 *  4. Parse the body into a structured rows array (see parse_csv()).
	 *  5. Store the rows in a transient for subsequent process_batch calls.
	 *  6. Return JSON success with the total row count.
	 *
	 * On any failure, wp_send_json_error() is called with a human-readable
	 * message; the JS layer surfaces it in the status div.
	 *
	 * @return void  (terminates via wp_send_json_* — never returns normally)
	 */
	public function handle_download(): void {
		// ── Security ──────────────────────────────────────────────────────────
		// check_ajax_referer() verifies the nonce AND calls wp_die() on failure,
		// so no manual die() is needed after it.
		check_ajax_referer( Admin::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				__( 'You do not have permission to perform this action.', 'woo-multi-stock' ),
				403
			);
		}

		// ── Retrieve and validate the stored URL ──────────────────────────────
		$csv_url = get_option( 'woo_multi_stock_csv_url', '' );

		if ( empty( $csv_url ) ) {
			wp_send_json_error(
				__( 'No CSV URL is configured. Please add a URL in the plugin settings and save.', 'woo-multi-stock' )
			);
		}

		// Normalise the URL one final time — esc_url_raw was already applied on
		// save, but options can be modified directly in the DB.
		$csv_url = esc_url_raw( $csv_url );

		// ── HTTP request ──────────────────────────────────────────────────────
		// Allow external code to adjust the timeout for unusually large files
		// or slow remote servers without modifying plugin source.
		$timeout = (int) apply_filters( 'woo_multi_stock_download_timeout', 60 );

		$response = wp_remote_get(
			$csv_url,
			array(
				'timeout'    => $timeout,
				// Some CSV servers reject generic user agents; identify ourselves.
				'user-agent' => 'WooMultiStock/' . WMS_VERSION . '; WordPress/' . get_bloginfo( 'version' ),
			)
		);

		// wp_remote_get() returns a WP_Error on transport-level failure
		// (DNS failure, connection refused, timeout, etc.).
		if ( is_wp_error( $response ) ) {
			wp_send_json_error(
				sprintf(
					/* translators: %s: WP_Error message */
					__( 'Could not reach the CSV URL: %s', 'woo-multi-stock' ),
					$response->get_error_message()
				)
			);
		}

		// A successful TCP connection is not enough — check the HTTP status code.
		$http_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== (int) $http_code ) {
			wp_send_json_error(
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
			wp_send_json_error(
				__( 'The CSV file is empty. Nothing to process.', 'woo-multi-stock' )
			);
		}

		// Guard: detect if the server returned an HTML page instead of CSV data.
		// This typically happens when using a Google Drive "view" share link
		// (drive.google.com/file/d/…/view) instead of a direct download URL
		// (drive.google.com/uc?export=download&id=…). The HTML viewer returns
		// HTTP 200, so the status code check above cannot catch it.
		$body_start = ltrim( substr( $body, 0, 100 ) );
		if ( preg_match( '/^<!(?:DOCTYPE|doctype)\s/i', $body_start )
			|| preg_match( '/^<html[\s>]/i', $body_start ) ) {
			wp_send_json_error(
				__( 'The URL returned an HTML page instead of CSV data. If you are using Google Drive, replace the share/view link with the direct download URL: drive.google.com/uc?export=download&id=FILE_ID', 'woo-multi-stock' )
			);
		}

		$rows = $this->parse_csv( $body );

		if ( empty( $rows ) ) {
			wp_send_json_error(
				__( 'No valid rows found in the CSV after parsing. Check the file format (delimiter: ;).', 'woo-multi-stock' )
			);
		}

		// ── Cache rows ────────────────────────────────────────────────────────
		// set_transient() serialises the PHP array automatically.
		// Overwrite any previous transient so the sync always uses fresh data.
		set_transient( self::TRANSIENT_KEY, $rows, self::TRANSIENT_TTL );

		// Return the total count so JS can calculate progress percentages.
		wp_send_json_success( array( 'total' => count( $rows ) ) );
	}

	/**
	 * AJAX handler: process one batch of CSV rows.
	 *
	 * Flow:
	 *  1. Verify nonce + capability.
	 *  2. Read offset from POST.
	 *  3. Load the cached rows from the transient.
	 *  4. Fire woo_multi_stock_before_processing hook.
	 *  5. Slice the batch: rows[offset .. offset + BATCH_SIZE - 1].
	 *  6. For each row, call Stock_Updater::update() and track results.
	 *  7. Return JSON with cumulative counters, next offset, and is_done flag.
	 *
	 * JS calls this action in a tail-recursive loop until is_done === true,
	 * updating the progress bar and counters after every response.
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

		// ── Read and sanitise the offset ──────────────────────────────────────
		// absint() converts to absolute integer, safely handling missing/invalid
		// values (returns 0 for empty string, negative, or non-numeric input).
		$offset = absint( isset( $_POST['offset'] ) ? $_POST['offset'] : 0 ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		// ── Load cached rows ──────────────────────────────────────────────────
		$all_rows = get_transient( self::TRANSIENT_KEY );

		// If the transient is missing or expired, the user must re-download.
		// This can happen if:
		//  - The sync took longer than TRANSIENT_TTL (unlikely for typical files).
		//  - The transient was manually deleted (e.g., via a cache flush plugin).
		//  - The user refreshed the page mid-sync and re-started without re-downloading.
		if ( false === $all_rows || ! is_array( $all_rows ) ) {
			wp_send_json_error(
				__( 'CSV data has expired or is missing. Please click "Start Sync" again to re-download the file.', 'woo-multi-stock' )
			);
		}

		$total = count( $all_rows );

		// ── Hook: before processing ───────────────────────────────────────────
		// Fires at the start of each batch. Useful for logging, rate-limiting,
		// or custom pre-processing logic. Passes the total row count so
		// consumers can calculate overall progress independently.
		//
		// Example usage:
		//   add_action( 'woo_multi_stock_before_processing', function( $total ) {
		//       error_log( 'WMS: starting batch, total rows = ' . $total );
		//   } );
		do_action( 'woo_multi_stock_before_processing', $total );

		// ── Slice the batch ───────────────────────────────────────────────────
		// array_slice() is non-destructive — the full $all_rows array stays in
		// the transient and is read again on the next request.
		$batch = array_slice( $all_rows, $offset, self::BATCH_SIZE );

		// ── Process each row ──────────────────────────────────────────────────
		$updater   = new Stock_Updater();
		$processed = 0;
		$not_found = 0;
		$updated   = 0;

		foreach ( $batch as $row ) {
			// Each $row is ['sku' => string, 'qty' => int] as built by parse_csv().
			$result = $updater->update( $row['sku'], $row['qty'] );

			$processed++;

			// Stock_Updater::update() returns the product ID (int > 0) on
			// success, or false when no product matches the SKU.
			if ( false === $result ) {
				$not_found++;
			} else {
				$updated++;
			}
		}

		// ── Calculate next state ──────────────────────────────────────────────
		$next_offset = $offset + count( $batch );
		$is_done     = ( $next_offset >= $total );

		// When done, clean up the transient immediately — no need to wait for TTL.
		if ( $is_done ) {
			delete_transient( self::TRANSIENT_KEY );
		}

		// Return all counters for this batch plus navigation state for the JS loop.
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
	 *  - This correctly handles both integers ("5,0000000000000" → 5)
	 *    and decimal quantities ("2,5000000000000" → 2, truncated intentionally).
	 *
	 * Skipped rows:
	 *  - Blank lines.
	 *  - Rows with fewer than 2 columns (malformed).
	 *  - Rows where the SKU column is empty after trimming.
	 *
	 * @param string $body  Raw HTTP response body (full CSV file content).
	 * @return array        Array of [ 'sku' => string, 'qty' => int ] maps.
	 *                      Empty array if the file has no valid data rows.
	 */
	private function parse_csv( string $body ): array {
		// ── Strip UTF-8 BOM ───────────────────────────────────────────────────
		// Files exported from Excel or Windows systems often begin with the
		// 3-byte BOM sequence \xEF\xBB\xBF. Without this guard, the BOM would
		// attach to the first SKU of the first *data* row if the header were
		// ever absent, causing that SKU lookup to silently fail.
		// Using substr() comparison rather than ltrim() avoids accidentally
		// stripping legitimate leading bytes that happen to match BOM bytes.
		if ( "\xEF\xBB\xBF" === substr( $body, 0, 3 ) ) {
			$body = substr( $body, 3 );
		}

		// Normalise line endings: split on \r\n (Windows), \r (old Mac), \n (Unix).
		// trim() removes any trailing newline so we don't get a phantom empty row.
		$lines = preg_split( '/\r\n|\r|\n/', trim( $body ) );

		if ( empty( $lines ) ) {
			return array();
		}

		// ── Skip the header row ───────────────────────────────────────────────
		// The CSV always has a header as its first line (e.g. "SKU;QTY").
		// array_shift() removes and returns the first element, advancing the
		// array so the foreach loop below starts at the first data row.
		array_shift( $lines );

		$rows = array();

		foreach ( $lines as $line ) {
			// Skip completely blank lines (common at end-of-file).
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}

			// str_getcsv() respects quoted fields and escaped delimiters,
			// which a naive explode(';', $line) would not handle correctly.
			$parts = str_getcsv( $line, ';' );

			// Need at least 2 columns: SKU and quantity.
			if ( count( $parts ) < 2 ) {
				continue;
			}

			$sku     = trim( $parts[0] );
			$qty_raw = trim( $parts[1] ); // e.g. "1,0000000000000"

			// Skip rows with an empty SKU — they cannot match any product.
			if ( '' === $sku ) {
				continue;
			}

			// ── Quantity conversion ───────────────────────────────────────────
			// Step 1: replace European decimal comma with dot.
			$qty_dot = str_replace( ',', '.', $qty_raw ); // "1,0000000000000" → "1.0000000000000"

			// Step 2: cast to int via float to handle decimal strings correctly.
			// (int)"1.5" = 1  ✓   (int)"1.0000000000000" = 1  ✓
			$qty = (int) (float) $qty_dot;

			$rows[] = array(
				'sku' => $sku,
				'qty' => $qty,
			);
		}

		return $rows;
	}
}
