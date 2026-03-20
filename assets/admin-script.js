/**
 * Woo Multi Stock — Admin Script v1.1.0
 *
 * Drives the multi-warehouse stock synchronisation UI on the plugin's admin page.
 *
 * RESPONSIBILITIES
 * ────────────────
 * A) Warehouse management — add / remove rows in the warehouses table,
 *    update the live meta-key preview, save via wms_save_warehouses AJAX.
 * B) Per-warehouse sync — each sync block has its own Download + Batch loop,
 *    progress bar and live counters.
 * C) Sync All — wms_total_prepare + wms_total_batch loop writes the sum of
 *    all _stock_* metas to WC _stock (does NOT re-download any CSV).
 * D) Stock overview table — paginated (50/page) AJAX table with SKU search,
 *    built from the wms_stock_table_fetch response.
 *
 * DATA CONTRACT (wmsData — injected by wp_localize_script)
 * ─────────────────────────────────────────────────────────
 * wmsData = {
 *   ajaxUrl   : string,
 *   nonce     : string,
 *   batchSize : number,
 *   warehouses: [ { id, label, csv_url }, … ],
 *   i18n      : { … }
 * }
 *
 * @package WooMultiStock
 */

/* global wmsData, jQuery */

( function ( $ ) {
	'use strict';

	// ── Bootstrap ───────────────────────────────────────────────────────────
	$( function () {
		initWarehouseManager();
		initSyncBlocks();
		initSyncAll();
		initStockTable();
	} );

	// ═══════════════════════════════════════════════════════════════════════
	// SECTION A — Warehouse manager
	// ═══════════════════════════════════════════════════════════════════════

	function initWarehouseManager() {
		var $tbody   = $( '#wms-warehouses-tbody' );
		var $addBtn  = $( '#wms-add-warehouse' );
		var $saveBtn = $( '#wms-save-warehouses' );
		var $status  = $( '#wms-save-status' );

		// Live meta-key preview when the user types in a label input.
		$tbody.on( 'input', '.wms-wh-label', function () {
			var label   = $( this ).val();
			var metaKey = '_stock_' + label.replace( /[^A-Za-z0-9]/g, '' );
			$( this ).closest( 'tr' ).find( '.wms-wh-meta-preview' ).text( metaKey );
		} );

		// Remove a warehouse row.
		$tbody.on( 'click', '.wms-remove-wh', function () {
			$( this ).closest( 'tr' ).remove();
		} );

		// Add a new empty warehouse row.
		$addBtn.on( 'click', function () {
			var row = '<tr class="wms-wh-row" data-id="">' +
				'<td><input type="text" class="regular-text wms-wh-label" value="" placeholder="' + escAttr( wmsData.i18n.addWarehouse ) + '"></td>' +
				'<td><code class="wms-wh-meta-preview">_stock_</code></td>' +
				'<td><input type="url" class="regular-text wms-wh-url" value="" placeholder="https://example.com/stock.csv" style="width:100%"></td>' +
				'<td><button type="button" class="button wms-remove-wh">' + escHtml( wmsData.i18n.removeWarehouse ) + '</button></td>' +
				'</tr>';
			$tbody.append( row );
		} );

		// Save warehouses via AJAX.
		$saveBtn.on( 'click', function () {
			var warehouses = [];

			$tbody.find( '.wms-wh-row' ).each( function () {
				var $row  = $( this );
				var id    = $row.data( 'id' ) || '';
				var label = $row.find( '.wms-wh-label' ).val().trim();
				var url   = $row.find( '.wms-wh-url' ).val().trim();

				if ( label ) {
					warehouses.push( { id: id, label: label, csv_url: url } );
				}
			} );

			$saveBtn.prop( 'disabled', true );
			$status.text( '' );

			$.ajax( {
				url   : wmsData.ajaxUrl,
				method: 'POST',
				data  : {
					action    : 'wms_save_warehouses',
					nonce     : wmsData.nonce,
					warehouses: JSON.stringify( warehouses ),
				},
			} )
			.done( function ( response ) {
				if ( response.success ) {
					$status.css( 'color', 'green' ).text( wmsData.i18n.savedOk );
					// Update data-id on rows that got a server-assigned id.
					var saved = response.data.warehouses || [];
					$tbody.find( '.wms-wh-row' ).each( function ( i ) {
						if ( saved[ i ] ) {
							$( this ).data( 'id', saved[ i ].id ).attr( 'data-id', saved[ i ].id );
						}
					} );
				} else {
					$status.css( 'color', 'red' ).text( wmsData.i18n.savedError + ' ' + extractMessage( response ) );
				}
			} )
			.fail( function () {
				$status.css( 'color', 'red' ).text( wmsData.i18n.savedError );
			} )
			.always( function () {
				$saveBtn.prop( 'disabled', false );
			} );
		} );
	}

	// ═══════════════════════════════════════════════════════════════════════
	// SECTION B — Per-warehouse sync
	// ═══════════════════════════════════════════════════════════════════════

	function initSyncBlocks() {
		$( '.wms-sync-block' ).each( function () {
			initOneSyncBlock( $( this ) );
		} );
	}

	/**
	 * Wire up one warehouse sync block.
	 *
	 * Each block maintains its own state so multiple warehouses can be synced
	 * independently (though not simultaneously — the guard prevents that).
	 *
	 * @param {jQuery} $block  The .wms-sync-block element.
	 */
	function initOneSyncBlock( $block ) {
		var warehouseId = $block.data( 'id' );

		var $btn          = $block.find( '.wms-sync-btn' );
		var $progressWrap = $block.find( '.wms-progress-wrap' );
		var $progressBar  = $block.find( '.wms-progress-bar' );
		var $progressText = $block.find( '.wms-progress-text' );
		var $counters     = $block.find( '.wms-counters' );
		var $cProcessed   = $block.find( '.wms-c-processed' );
		var $cNotFound    = $block.find( '.wms-c-not-found' );
		var $cUpdated     = $block.find( '.wms-c-updated' );
		var $status       = $block.find( '.wms-sync-status' );

		var state = {
			isSyncing    : false,
			totalRows    : 0,
			processed    : 0,
			notFound     : 0,
			updated      : 0,
			currentOffset: 0,
		};

		$btn.on( 'click', function ( e ) {
			e.preventDefault();
			if ( state.isSyncing ) { return; }
			startWarehouseSync();
		} );

		function startWarehouseSync() {
			state.isSyncing     = true;
			state.totalRows     = 0;
			state.processed     = 0;
			state.notFound      = 0;
			state.updated       = 0;
			state.currentOffset = 0;

			$btn.prop( 'disabled', true ).text( wmsData.i18n.syncing );
			$progressWrap.show();
			$counters.show();
			$cProcessed.text( '0' );
			$cNotFound.text( '0' );
			$cUpdated.text( '0' );
			setProgress( 0 );
			$status.html( '' ).removeClass( 'notice notice-success notice-error' );

			downloadWarehouseCSV();
		}

		function downloadWarehouseCSV() {
			$.ajax( {
				url   : wmsData.ajaxUrl,
				method: 'POST',
				data  : {
					action      : 'woo_multi_stock_download',
					nonce       : wmsData.nonce,
					warehouse_id: warehouseId,
				},
			} )
			.done( function ( response ) {
				if ( ! response.success ) {
					showBlockError( wmsData.i18n.errorDownload + ' ' + extractMessage( response ) );
					return;
				}

				state.totalRows     = parseInt( response.data.total, 10 ) || 0;
				state.currentOffset = 0;

				if ( state.totalRows === 0 ) {
					showBlockError( wmsData.i18n.errorDownload );
					return;
				}

				processBatch();
			} )
			.fail( function ( _jqXHR, textStatus ) {
				showBlockError( wmsData.i18n.errorDownload + ' (' + textStatus + ')' );
			} );
		}

		function processBatch() {
			if ( ! state.isSyncing ) { return; }

			$.ajax( {
				url   : wmsData.ajaxUrl,
				method: 'POST',
				data  : {
					action      : 'woo_multi_stock_process_batch',
					nonce       : wmsData.nonce,
					warehouse_id: warehouseId,
					offset      : state.currentOffset,
				},
			} )
			.done( function ( response ) {
				if ( ! response.success ) {
					showBlockError( wmsData.i18n.errorBatch + ' ' + extractMessage( response ) );
					return;
				}

				var d = response.data;
				state.processed     += parseInt( d.processed,   10 ) || 0;
				state.notFound      += parseInt( d.not_found,   10 ) || 0;
				state.updated       += parseInt( d.updated,     10 ) || 0;
				state.currentOffset  = parseInt( d.next_offset, 10 ) || state.currentOffset;

				updateBlockUI();

				if ( d.is_done ) {
					finishWarehouseSync();
				} else {
					processBatch();
				}
			} )
			.fail( function ( _jqXHR, textStatus ) {
				showBlockError( wmsData.i18n.errorBatch + ' (' + textStatus + ')' );
			} );
		}

		function updateBlockUI() {
			var pct = state.totalRows > 0
				? Math.min( 100, Math.round( ( state.currentOffset / state.totalRows ) * 100 ) )
				: 0;
			setProgress( pct );
			$cProcessed.text( state.processed );
			$cNotFound.text( state.notFound );
			$cUpdated.text( state.updated );
		}

		function setProgress( pct ) {
			$progressBar.attr( { value: pct, max: 100 } );
			$progressText.text( pct + '%' );
		}

		function finishWarehouseSync() {
			state.currentOffset = state.totalRows;
			updateBlockUI();

			var summary = sprintfSimple(
				wmsData.i18n.summaryTpl,
				state.processed,
				state.notFound,
				state.updated
			);

			$status
				.addClass( 'notice notice-success' )
				.html( '<p><strong>' + escHtml( wmsData.i18n.done ) + '</strong> ' + escHtml( summary ) + '</p>' );

			state.isSyncing = false;
			$btn.prop( 'disabled', false ).text( wmsData.i18n.startSync );
		}

		function showBlockError( message ) {
			$status
				.removeClass( 'notice-success' )
				.addClass( 'notice notice-error' )
				.html( '<p>' + escHtml( message ) + '</p>' );

			state.isSyncing = false;
			$btn.prop( 'disabled', false ).text( wmsData.i18n.startSync );
		}
	}

	// ═══════════════════════════════════════════════════════════════════════
	// SECTION C — Sync All (Total_Updater)
	// ═══════════════════════════════════════════════════════════════════════

	function initSyncAll() {
		var $btn    = $( '#wms-sync-all' );
		var $wrap   = $( '#wms-syncall-wrap' );
		var $bar    = $( '#wms-syncall-bar' );
		var $text   = $( '#wms-syncall-text' );
		var $status = $( '#wms-syncall-status' );

		var state = { running: false, total: 0, processed: 0, offset: 0 };

		$btn.on( 'click', function () {
			if ( state.running ) { return; }
			state.running   = true;
			state.total     = 0;
			state.processed = 0;
			state.offset    = 0;

			$btn.prop( 'disabled', true ).text( wmsData.i18n.calculating );
			$wrap.show();
			$bar.attr( { value: 0, max: 100 } );
			$text.text( '0%' );
			$status.html( '' ).removeClass( 'notice notice-success notice-error' );

			prepareTotals();
		} );

		function prepareTotals() {
			$.ajax( {
				url   : wmsData.ajaxUrl,
				method: 'POST',
				data  : { action: 'wms_total_prepare', nonce: wmsData.nonce },
			} )
			.done( function ( response ) {
				if ( ! response.success ) {
					showAllError( wmsData.i18n.errorCalc + ' ' + extractMessage( response ) );
					return;
				}

				state.total  = parseInt( response.data.total, 10 ) || 0;
				state.offset = 0;

				if ( state.total === 0 ) {
					showAllError( wmsData.i18n.errorCalc );
					return;
				}

				processTotalBatch();
			} )
			.fail( function ( _jqXHR, textStatus ) {
				showAllError( wmsData.i18n.errorCalc + ' (' + textStatus + ')' );
			} );
		}

		function processTotalBatch() {
			if ( ! state.running ) { return; }

			$.ajax( {
				url   : wmsData.ajaxUrl,
				method: 'POST',
				data  : { action: 'wms_total_batch', nonce: wmsData.nonce, offset: state.offset },
			} )
			.done( function ( response ) {
				if ( ! response.success ) {
					showAllError( wmsData.i18n.errorCalc + ' ' + extractMessage( response ) );
					return;
				}

				var d = response.data;
				state.processed += parseInt( d.processed, 10 ) || 0;
				state.offset     = parseInt( d.next_offset, 10 ) || state.offset;

				var pct = state.total > 0
					? Math.min( 100, Math.round( ( state.offset / state.total ) * 100 ) )
					: 0;
				$bar.attr( { value: pct, max: 100 } );
				$text.text( pct + '%' );

				if ( d.is_done ) {
					finishAll();
				} else {
					processTotalBatch();
				}
			} )
			.fail( function ( _jqXHR, textStatus ) {
				showAllError( wmsData.i18n.errorCalc + ' (' + textStatus + ')' );
			} );
		}

		function finishAll() {
			$bar.attr( { value: 100, max: 100 } );
			$text.text( '100%' );

			var summary = sprintfSimple( wmsData.i18n.calcSummary, state.processed );

			$status
				.addClass( 'notice notice-success' )
				.html( '<p><strong>' + escHtml( wmsData.i18n.calcDone ) + '</strong> ' + escHtml( summary ) + '</p>' );

			state.running = false;
			$btn.prop( 'disabled', false ).text( wmsData.i18n.syncAll );
		}

		function showAllError( message ) {
			$status
				.removeClass( 'notice-success' )
				.addClass( 'notice notice-error' )
				.html( '<p>' + escHtml( message ) + '</p>' );

			state.running = false;
			$btn.prop( 'disabled', false ).text( wmsData.i18n.syncAll );
		}
	}

	// ═══════════════════════════════════════════════════════════════════════
	// SECTION D — Stock overview table
	// ═══════════════════════════════════════════════════════════════════════

	function initStockTable() {
		var $tbody    = $( '#wms-stock-tbody' );
		var $thead    = $( '#wms-stock-thead' );
		var $searchIn = $( '#wms-search-sku' );
		var $searchBtn = $( '#wms-search-btn' );
		var $prevBtn  = $( '#wms-prev-page' );
		var $nextBtn  = $( '#wms-next-page' );
		var $pageInfo = $( '#wms-page-info' );

		var currentPage = 1;
		var totalPages  = 1;
		var searchSku   = '';
		var loading     = false;

		// Load page 1 immediately.
		fetchPage( 1, '' );

		$searchBtn.on( 'click', function () {
			searchSku   = $searchIn.val().trim();
			currentPage = 1;
			fetchPage( currentPage, searchSku );
		} );

		$searchIn.on( 'keypress', function ( e ) {
			if ( 13 === e.which ) {
				$searchBtn.trigger( 'click' );
			}
		} );

		$prevBtn.on( 'click', function () {
			if ( currentPage > 1 && ! loading ) {
				fetchPage( currentPage - 1, searchSku );
			}
		} );

		$nextBtn.on( 'click', function () {
			if ( currentPage < totalPages && ! loading ) {
				fetchPage( currentPage + 1, searchSku );
			}
		} );

		function fetchPage( page, sku ) {
			if ( loading ) { return; }
			loading = true;

			var colCount = 3 + ( wmsData.warehouses ? wmsData.warehouses.length : 0 );
			$tbody.html( '<tr><td colspan="' + colCount + '" style="text-align:center">' + escHtml( wmsData.i18n.loading ) + '</td></tr>' );
			$prevBtn.prop( 'disabled', true );
			$nextBtn.prop( 'disabled', true );

			$.ajax( {
				url   : wmsData.ajaxUrl,
				method: 'POST',
				data  : {
					action    : 'wms_stock_table_fetch',
					nonce     : wmsData.nonce,
					page      : page,
					search_sku: sku,
				},
			} )
			.done( function ( response ) {
				loading = false;

				if ( ! response.success ) {
					$tbody.html( '<tr><td colspan="' + colCount + '">' + escHtml( extractMessage( response ) ) + '</td></tr>' );
					return;
				}

				var d           = response.data;
				currentPage     = d.current_page;
				totalPages      = d.total_pages || 1;
				var rows        = d.rows || [];
				var labels      = d.warehouse_labels || [];

				// Rebuild thead columns to match the labels returned by the server.
				rebuildThead( labels );

				if ( rows.length === 0 ) {
					$tbody.html( '<tr><td colspan="' + ( 3 + labels.length ) + '" style="text-align:center">' + escHtml( wmsData.i18n.noResults ) + '</td></tr>' );
				} else {
					var html = '';
					for ( var i = 0; i < rows.length; i++ ) {
						var row = rows[ i ];
						html += '<tr>';
						html += '<td><code>' + escHtml( row.sku ) + '</code></td>';
						html += '<td>' + escHtml( row.name ) + '</td>';
						html += '<td style="text-align:right">' + parseInt( row.wc_stock, 10 ) + '</td>';
						for ( var j = 0; j < labels.length; j++ ) {
							var qty = row.warehouses && row.warehouses[ labels[ j ] ] !== undefined
								? parseInt( row.warehouses[ labels[ j ] ], 10 )
								: 0;
							html += '<td style="text-align:right">' + qty + '</td>';
						}
						html += '</tr>';
					}
					$tbody.html( html );
				}

				// Update pagination.
				var infoTpl = wmsData.i18n.pageInfo || 'Page %1$d of %2$d';
				$pageInfo.text( sprintfSimple( infoTpl, currentPage, totalPages ) );
				$prevBtn.prop( 'disabled', currentPage <= 1 );
				$nextBtn.prop( 'disabled', currentPage >= totalPages );
			} )
			.fail( function () {
				loading = false;
				$tbody.html( '<tr><td colspan="' + colCount + '" style="text-align:center;color:red">' + escHtml( wmsData.i18n.noResults ) + '</td></tr>' );
				$prevBtn.prop( 'disabled', true );
				$nextBtn.prop( 'disabled', true );
			} );
		}

		function rebuildThead( labels ) {
			// Preserve static columns and rebuild warehouse columns dynamically.
			var html = '<tr id="wms-stock-thead">';
			html += '<th style="width:14%">SKU</th>';
			html += '<th>' + escHtml( 'Product / Variation' ) + '</th>';
			html += '<th style="width:10%">WC Stock</th>';
			for ( var i = 0; i < labels.length; i++ ) {
				html += '<th style="width:10%">' + escHtml( labels[ i ] ) + '</th>';
			}
			html += '</tr>';
			$thead.html( html );
		}
	}

	// ═══════════════════════════════════════════════════════════════════════
	// UTILITIES (shared across all sections)
	// ═══════════════════════════════════════════════════════════════════════

	/**
	 * Positional sprintf: replaces %1$d, %2$d, %3$d with integer values.
	 *
	 * @param {string} tpl   Template string.
	 * @param {...number}    Positional values (1-indexed).
	 * @return {string}
	 */
	function sprintfSimple( tpl ) {
		var result = tpl;
		for ( var i = 1; i < arguments.length; i++ ) {
			result = result.replace( new RegExp( '%' + i + '\\$d', 'g' ), arguments[ i ] );
		}
		return result;
	}

	/**
	 * HTML-escape a string using the DOM.
	 *
	 * @param {string} str
	 * @return {string}
	 */
	function escHtml( str ) {
		return $( '<div>' ).text( String( str ) ).html();
	}

	/**
	 * Attribute-escape a string.
	 *
	 * @param {string} str
	 * @return {string}
	 */
	function escAttr( str ) {
		return escHtml( str )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#039;' );
	}

	/**
	 * Extract a message from a wp_send_json_error() response.
	 *
	 * @param {Object} response
	 * @return {string}
	 */
	function extractMessage( response ) {
		if ( ! response || ! response.data ) { return ''; }
		if ( typeof response.data === 'string' ) { return response.data; }
		if ( typeof response.data.message === 'string' ) { return response.data.message; }
		return '';
	}

}( jQuery ) );
