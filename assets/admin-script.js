/**
 * Woo Multi Stock — Admin Script
 *
 * Drives the stock synchronisation UI on the plugin's settings page.
 *
 * RESPONSIBILITIES
 * ────────────────
 * 1. Bind the "Start Sync" button click event.
 * 2. Call the woo_multi_stock_download AJAX action once to fetch and cache the
 *    CSV on the server, receiving back the total row count.
 * 3. Call woo_multi_stock_process_batch repeatedly (tail-recursive async loop),
 *    advancing the offset by BATCH_SIZE on each successful response.
 * 4. Update the progress bar and live counters after every batch.
 * 5. Display a summary notice when all batches are complete.
 * 6. Surface error messages in the status area on any failure.
 *
 * DATA CONTRACT (wmsData — injected by wp_localize_script in Class-Admin.php)
 * ────────────────────────────────────────────────────────────────────────────
 * wmsData = {
 *   ajaxUrl  : string,   // admin-ajax.php URL
 *   nonce    : string,   // wp_create_nonce( 'woo_multi_stock_ajax_nonce' )
 *   batchSize: number,   // rows per request (mirrors PHP BATCH_SIZE = 50)
 *   i18n     : {
 *     startSync    : string,  // "Start Sync"
 *     syncing      : string,  // "Syncing…"
 *     done         : string,  // "Sync complete."
 *     errorDownload: string,  // "Failed to download CSV…"
 *     errorBatch   : string,  // "An error occurred during batch…"
 *     summaryTpl   : string,  // "Processed %1$d products, %2$d SKUs not found, %3$d SKUs updated."
 *   }
 * }
 *
 * AJAX FLOW OVERVIEW
 * ──────────────────
 *
 *   [click] → startSync()
 *               └─ downloadCSV()  ──POST──▶ woo_multi_stock_download
 *                                  ◀── { total: N }
 *               └─ processBatch() ──POST──▶ woo_multi_stock_process_batch (offset=0)
 *                                  ◀── { processed, not_found, updated, next_offset, is_done:false }
 *               └─ processBatch() ──POST──▶ … (offset=50, 100, …)
 *                                  ◀── { …, is_done:true }
 *               └─ finishSync()
 *
 * @package WooMultiStock
 */

/* global wmsData, jQuery */

( function ( $ ) {
	'use strict';

	// ── DOM element references ─────────────────────────────────────────────
	// Cached once on DOMContentLoaded — avoids repeated jQuery lookups inside
	// the AJAX loop, which runs potentially hundreds of times for large CSVs.
	var $btnStart;
	var $progressWrap;
	var $progressBar;
	var $progressText;
	var $countersWrap;
	var $countProcessed;
	var $countNotFound;
	var $countUpdated;
	var $statusMessage;

	// ── Module-level state ─────────────────────────────────────────────────
	// These variables hold the sync session state across all async AJAX calls.
	// They are reset at the start of each new sync via resetState().

	/** Total rows in the CSV, returned by the download action. */
	var totalRows = 0;

	/** Cumulative number of rows passed to Stock_Updater (processed + skipped). */
	var processed = 0;

	/** Cumulative SKUs that had no matching WooCommerce product. */
	var notFound = 0;

	/** Cumulative SKUs successfully written to _stock_CMT. */
	var updated = 0;

	/** Current row offset sent to process_batch. Advances by batchSize each call. */
	var currentOffset = 0;

	/**
	 * Guard flag — true while a sync session is in progress.
	 * Prevents double-clicks from starting a second parallel sync.
	 * Also serves as a future cancellation hook: setting isSyncing = false
	 * inside processBatch() will cause the tail-recursive loop to stop.
	 */
	var isSyncing = false;

	// ── Entry point ────────────────────────────────────────────────────────

	/**
	 * Initialise the module once the DOM is ready.
	 * Called immediately by the jQuery ready wrapper at the bottom of this file.
	 */
	function init() {
		// Cache DOM references once.
		$btnStart       = $( '#wms-start-sync' );
		$progressWrap   = $( '#wms-progress-wrap' );
		$progressBar    = $( '#wms-progress-bar' );
		$progressText   = $( '#wms-progress-text' );
		$countersWrap   = $( '#wms-counters' );
		$countProcessed = $( '#wms-count-processed' );
		$countNotFound  = $( '#wms-count-not-found' );
		$countUpdated   = $( '#wms-count-updated' );
		$statusMessage  = $( '#wms-status-message' );

		initSyncButton();
	}

	// ── Button binding ─────────────────────────────────────────────────────

	/**
	 * Bind the click handler on the "Start Sync" button.
	 *
	 * The isSyncing guard prevents a second click from launching a parallel
	 * sync session (which would corrupt the cumulative counters and confuse
	 * the server-side transient state).
	 */
	function initSyncButton() {
		$btnStart.on( 'click', function ( e ) {
			e.preventDefault();

			if ( isSyncing ) {
				// Already running — ignore the click silently.
				// The button is also visually disabled (see lockUI), but this
				// guard handles programmatic calls and edge cases.
				return;
			}

			startSync();
		} );
	}

	// ── Sync orchestration ─────────────────────────────────────────────────

	/**
	 * Begin a new sync session.
	 *
	 * Resets all state variables and counters to zero, updates the UI to the
	 * "syncing" state, then fires the first AJAX call (downloadCSV).
	 */
	function startSync() {
		// Reset all session state to zero values.
		resetState();

		// Update UI to "running" state.
		lockUI();
		resetCounters();
		showProgressArea();
		clearStatusMessage();
		updateUI(); // Renders 0% immediately so the user sees immediate feedback.

		// Step 1: download and cache the CSV on the server.
		downloadCSV();
	}

	/**
	 * AJAX call 1 of N: download and cache the CSV.
	 *
	 * Sends a POST to woo_multi_stock_download. On success, stores the total
	 * row count and immediately kicks off the first process_batch call.
	 * On failure, surfaces the error and resets the UI.
	 */
	function downloadCSV() {
		$.ajax( {
			url    : wmsData.ajaxUrl,
			method : 'POST',
			data   : {
				action: 'woo_multi_stock_download',
				nonce : wmsData.nonce,
			},
		} )
		.done( function ( response ) {
			// WordPress AJAX always wraps the payload in { success: bool, data: * }.
			if ( ! response.success ) {
				// PHP sent wp_send_json_error() — show the error message from PHP.
				showError( wmsData.i18n.errorDownload + ' ' + extractMessage( response ) );
				unlockUI();
				return;
			}

			// Store the total row count returned by handle_download().
			totalRows     = parseInt( response.data.total, 10 ) || 0;
			currentOffset = 0;

			if ( totalRows === 0 ) {
				// Parsed fine but empty — nothing to do.
				showError( wmsData.i18n.errorDownload );
				unlockUI();
				return;
			}

			// Step 2: start the batch-processing loop.
			processBatch();
		} )
		.fail( function ( jqXHR, textStatus ) {
			// Network-level failure (no response, timeout, CORS, etc.).
			showError( wmsData.i18n.errorDownload + ' (' + textStatus + ')' );
			unlockUI();
		} );
	}

	/**
	 * AJAX call 2…N: process one batch of rows.
	 *
	 * This function calls itself recursively (via the .done() callback) until
	 * the server signals is_done === true. Because each call is asynchronous,
	 * the browser never blocks — this is not true recursion, just sequential
	 * async chaining.
	 *
	 * WHY NOT setTimeout(0)?
	 * $.ajax is already asynchronous. Scheduling via setTimeout would only add
	 * latency without giving the browser any additional time to paint, since
	 * the repaint happens after the current synchronous execution stack anyway.
	 * Calling processBatch() directly inside .done() is clean and efficient.
	 *
	 * CANCELLATION:
	 * If isSyncing is set to false externally (e.g. a future "Cancel" button),
	 * the guard at the top of this function stops the loop cleanly without
	 * orphaning any in-flight request.
	 */
	function processBatch() {
		// Cancellation checkpoint.
		if ( ! isSyncing ) {
			return;
		}

		$.ajax( {
			url    : wmsData.ajaxUrl,
			method : 'POST',
			data   : {
				action: 'woo_multi_stock_process_batch',
				nonce : wmsData.nonce,
				offset: currentOffset,
			},
		} )
		.done( function ( response ) {
			if ( ! response.success ) {
				showError( wmsData.i18n.errorBatch + ' ' + extractMessage( response ) );
				unlockUI();
				return;
			}

			var data = response.data;

			// ── Accumulate counters ────────────────────────────────────────
			// The server returns per-batch counts; we add them to the running
			// totals so the UI always shows the cumulative progress.
			processed     += parseInt( data.processed,  10 ) || 0;
			notFound      += parseInt( data.not_found,  10 ) || 0;
			updated       += parseInt( data.updated,    10 ) || 0;
			currentOffset  = parseInt( data.next_offset, 10 ) || currentOffset;

			// Refresh the progress bar and counter elements.
			updateUI();

			if ( data.is_done ) {
				// All batches complete — show the summary and restore the UI.
				finishSync();
			} else {
				// More batches remain — schedule the next one immediately.
				processBatch();
			}
		} )
		.fail( function ( jqXHR, textStatus ) {
			showError( wmsData.i18n.errorBatch + ' (' + textStatus + ')' );
			unlockUI();
		} );
	}

	// ── State helpers ──────────────────────────────────────────────────────

	/**
	 * Reset all session-level state variables to zero.
	 * Called at the start of each new sync via startSync().
	 */
	function resetState() {
		isSyncing     = true;
		totalRows     = 0;
		processed     = 0;
		notFound      = 0;
		updated       = 0;
		currentOffset = 0;
	}

	// ── UI helpers ─────────────────────────────────────────────────────────

	/**
	 * Disable the start button and update its label to signal activity.
	 * This prevents double-submission and gives visual feedback immediately.
	 */
	function lockUI() {
		$btnStart.prop( 'disabled', true ).text( wmsData.i18n.syncing );
	}

	/**
	 * Re-enable the start button and restore its original label.
	 * Called on both success (finishSync) and failure (showError).
	 */
	function unlockUI() {
		isSyncing = false;
		$btnStart.prop( 'disabled', false ).text( wmsData.i18n.startSync );
	}

	/**
	 * Show the progress bar container and the counters container.
	 * Both are hidden via inline style="display:none" in the PHP template and
	 * only shown once a sync session begins.
	 */
	function showProgressArea() {
		$progressWrap.show();
		$countersWrap.show();
	}

	/**
	 * Set all visible counter elements to zero.
	 * Keeps the DOM in sync with the resetState() call.
	 */
	function resetCounters() {
		$countProcessed.text( '0' );
		$countNotFound.text( '0' );
		$countUpdated.text( '0' );
	}

	/**
	 * Remove any previous status/error message from the status area.
	 */
	function clearStatusMessage() {
		$statusMessage.html( '' ).removeClass( 'notice notice-success notice-error' );
	}

	/**
	 * Update the progress bar value, the percentage text, and the counters
	 * to reflect the current session state.
	 *
	 * PROGRESS CALCULATION:
	 * Uses currentOffset (rows sent to the server so far) as the numerator,
	 * not `processed` (rows successfully matched). This gives a true percentage
	 * of "how far through the file we are" rather than "how many matched", which
	 * would be confusing if many SKUs are not found.
	 *
	 * Edge case: if totalRows is 0 (shouldn't happen normally), we default to 0%
	 * to avoid a division-by-zero NaN in the progress bar.
	 */
	function updateUI() {
		var percent = totalRows > 0
			? Math.min( 100, Math.round( ( currentOffset / totalRows ) * 100 ) )
			: 0;

		// The HTML5 <progress> element uses `value` and `max` attributes.
		$progressBar.attr( { value: percent, max: 100 } );
		$progressText.text( percent + '%' );

		// Update live counters.
		$countProcessed.text( processed );
		$countNotFound.text( notFound );
		$countUpdated.text( updated );
	}

	/**
	 * Called when all batches have completed successfully.
	 *
	 * Forces the progress bar to 100% (in case of rounding), then builds and
	 * displays the summary notice using the translatable template from wmsData.
	 */
	function finishSync() {
		// Force currentOffset to totalRows so updateUI() always renders 100%.
		currentOffset = totalRows;
		updateUI();

		// Build the summary string by substituting the three positional
		// placeholders in the PHP-side translated template string.
		// e.g. "Processed %1$d products, %2$d SKUs not found, %3$d SKUs updated."
		var summary = sprintfSimple( wmsData.i18n.summaryTpl, processed, notFound, updated );

		$statusMessage
			.addClass( 'notice notice-success' )
			.html(
				'<p><strong>' + escHtml( wmsData.i18n.done ) + '</strong> ' +
				escHtml( summary ) +
				'</p>'
			);

		unlockUI();
	}

	/**
	 * Display an error notice in the status area and restore the UI.
	 *
	 * @param {string} message  Human-readable error description to display.
	 */
	function showError( message ) {
		$statusMessage
			.removeClass( 'notice-success' )
			.addClass( 'notice notice-error' )
			.html( '<p>' + escHtml( message ) + '</p>' );

		unlockUI();
	}

	// ── Utility functions ──────────────────────────────────────────────────

	/**
	 * Minimal positional sprintf replacement.
	 *
	 * Replaces %1$d, %2$d, %3$d with the three supplied integer values.
	 * This is sufficient for the single summary template used in this plugin.
	 * A full printf implementation is not needed and would be over-engineering.
	 *
	 * @param  {string} template   Template string with %1$d, %2$d, %3$d.
	 * @param  {number} val1       Value for %1$d.
	 * @param  {number} val2       Value for %2$d.
	 * @param  {number} val3       Value for %3$d.
	 * @return {string}            Template with placeholders replaced.
	 */
	function sprintfSimple( template, val1, val2, val3 ) {
		return template
			.replace( '%1$d', val1 )
			.replace( '%2$d', val2 )
			.replace( '%3$d', val3 );
	}

	/**
	 * Minimal HTML escaping for safe insertion via .html().
	 *
	 * We output user-facing strings (translated text + server error messages)
	 * via jQuery's .html() method to allow the <strong> and <p> wrapper tags
	 * in finishSync()/showError(). Any dynamic values (the summary string,
	 * server error text) are passed through this function first to neutralise
	 * any HTML that may have been injected by the server response.
	 *
	 * Using jQuery's own $('<div>').text(str).html() pattern is the idiomatic
	 * jQuery approach — it lets the browser do the escaping via the DOM API,
	 * so no regex-based character substitution is needed.
	 *
	 * @param  {string} str  Raw string that may contain HTML special characters.
	 * @return {string}      HTML-escaped string safe for insertion into .html().
	 */
	function escHtml( str ) {
		return $( '<div>' ).text( str ).html();
	}

	/**
	 * Extract a human-readable message from a failed WordPress AJAX response.
	 *
	 * wp_send_json_error() places the message in response.data (string) or
	 * response.data.message (object). This helper handles both shapes so
	 * error reporting stays accurate regardless of which PHP path was taken.
	 *
	 * @param  {Object} response  The parsed jQuery AJAX response object.
	 * @return {string}           Error message string, or empty string if none.
	 */
	function extractMessage( response ) {
		if ( ! response || ! response.data ) {
			return '';
		}
		if ( typeof response.data === 'string' ) {
			return response.data;
		}
		if ( typeof response.data.message === 'string' ) {
			return response.data.message;
		}
		return '';
	}

	// ── Bootstrap ──────────────────────────────────────────────────────────

	// Run init() once jQuery signals the DOM is ready.
	// The IIFE pattern (function($){...}(jQuery)) ensures $ refers to jQuery
	// even if other libraries (Prototype, MooTools) are also present.
	$( init );

}( jQuery ) );
