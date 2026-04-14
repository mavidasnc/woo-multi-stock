<?php
/**
 * Class CLI
 *
 * Registers WP-CLI commands for Woo Multi Stock.
 *
 * AVAILABLE COMMANDS
 * ──────────────────
 *  wp wms sync [--warehouse=<id|all>]
 *      Downloads the CSV and updates _stock_* meta for one or all warehouses.
 *      Default: --warehouse=all
 *
 *  wp wms sync-total
 *      Sums all existing _stock_* metas and writes the total to WooCommerce
 *      native _stock. Does NOT re-download any CSV.
 *
 * CRON EXAMPLES
 * ─────────────
 *  # Sync all warehouses every night at 02:00, then update totals at 02:30
 *  0 2 * * * www-data wp wms sync --warehouse=all --path=/var/www/html --quiet >> /var/log/wms-sync.log 2>&1
 *  30 2 * * * www-data wp wms sync-total --path=/var/www/html --quiet >> /var/log/wms-sync.log 2>&1
 *
 * REGISTRATION
 * ────────────
 * The command group is registered in woo-multi-stock.php with:
 *   WP_CLI::add_command( 'wms', new \WooMultiStock\CLI() );
 * This call is placed inside plugins_loaded (priority 11) but outside the
 * is_admin() guard, because WP-CLI returns false for is_admin().
 *
 * @package WooMultiStock
 */

namespace WooMultiStock;

// Prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP-CLI commands for Woo Multi Stock warehouse synchronisation.
 *
 * @package WooMultiStock
 */
class CLI {

	/**
	 * Number of IDs processed per iteration in sync_total().
	 * Kept consistent with Total_Updater::BATCH_SIZE to avoid memory spikes.
	 *
	 * @var int
	 */
	private const BATCH_SIZE = 50;

	// ── Commands ──────────────────────────────────────────────────────────────

	/**
	 * Sync stock from CSV for one or all warehouses.
	 *
	 * Downloads the remote CSV, parses every row, and updates the
	 * per-warehouse meta field (_stock_CMT, _stock_WH2, …) for each
	 * matched product or variation SKU.
	 *
	 * ## OPTIONS
	 *
	 * [--warehouse=<id|all>]
	 * : Slug ID of the warehouse to sync, or 'all' to sync every configured
	 *   warehouse in sequence. Default: all
	 *
	 * ## EXAMPLES
	 *
	 *     wp wms sync --warehouse=cmt
	 *     wp wms sync --warehouse=all
	 *     wp wms sync
	 *
	 * @when after_wp_load
	 *
	 * @param array $args        Positional arguments (unused).
	 * @param array $assoc_args  Named arguments (warehouse).
	 * @return void
	 */
	public function sync( array $args, array $assoc_args ): void {
		$warehouse_arg = isset( $assoc_args['warehouse'] )
			? sanitize_title( (string) $assoc_args['warehouse'] )
			: 'all';

		$wm         = new Warehouse_Manager();
		$warehouses = $this->resolve_warehouses( $warehouse_arg, $wm );

		if ( empty( $warehouses ) ) {
			\WP_CLI::error( 'No warehouses found. Check the plugin configuration under WooCommerce → Woo Multi Stock.' );
			return; // Never reached — WP_CLI::error() exits. Keeps static analysers happy.
		}

		$processor       = new Processor();
		$total_updated   = 0;
		$total_not_found = 0;

		foreach ( $warehouses as $warehouse ) {
			\WP_CLI::log(
				sprintf( 'Syncing warehouse: %s (%s)', $warehouse['label'], $warehouse['id'] )
			);

			// ── 1. Download and parse CSV ──────────────────────────────────────
			$rows = $processor->fetch_rows( $warehouse );

			if ( is_wp_error( $rows ) ) {
				\WP_CLI::warning(
					sprintf( '[%s] Download failed: %s', $warehouse['label'], $rows->get_error_message() )
				);
				continue;
			}

			$total_rows = count( $rows );
			\WP_CLI::log( sprintf( '  Found %d rows in CSV.', $total_rows ) );

			// ── 2. Update meta for each row ────────────────────────────────────
			$meta_key = Warehouse_Manager::get_meta_key( $warehouse );
			$updater  = new Stock_Updater( $meta_key );

			$progress = \WP_CLI\Utils\make_progress_bar(
				sprintf( '  Processing %s', $warehouse['label'] ),
				$total_rows
			);

			$wh_updated   = 0;
			$wh_not_found = 0;

			foreach ( $rows as $row ) {
				$result = $updater->update( $row['sku'], $row['qty'] );

				if ( false === $result ) {
					$wh_not_found++;
				} else {
					$wh_updated++;
				}

				$progress->tick();
			}

			$progress->finish();

			\WP_CLI::log(
				sprintf(
					'  [%s] Done — updated: %d, not found: %d.',
					$warehouse['label'],
					$wh_updated,
					$wh_not_found
				)
			);

			$total_updated   += $wh_updated;
			$total_not_found += $wh_not_found;
		}

		\WP_CLI::success(
			sprintf(
				'Sync complete — total updated: %d, total not found: %d.',
				$total_updated,
				$total_not_found
			)
		);
	}

	/**
	 * Sum all _stock_* metas and update the native WooCommerce _stock field.
	 *
	 * Reads the per-warehouse meta values already in the database (written by
	 * 'wp wms sync') and aggregates them into WooCommerce's native _stock field
	 * for every product and variation. Does NOT re-download any CSV.
	 *
	 * For product variations that do not yet have manage_stock enabled, the
	 * flag is set to 'yes' so WooCommerce can properly track stock status.
	 *
	 * Run this command after one or more 'wp wms sync' calls to propagate the
	 * per-warehouse quantities to WooCommerce.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wms sync-total
	 *
	 * @when after_wp_load
	 *
	 * @param array $args        Positional arguments (unused).
	 * @param array $assoc_args  Named arguments (unused).
	 * @return void
	 */
	public function sync_total( array $args, array $assoc_args ): void {
		$updater = new Total_Updater();

		// ── Phase 1: collect all IDs ───────────────────────────────────────────
		\WP_CLI::log( 'Collecting product IDs with warehouse metas...' );

		$result        = $updater->collect_ids();
		$all_ids       = $result['ids'];
		$variation_ids = $result['variation_ids'];
		$total         = count( $all_ids );

		if ( 0 === $total ) {
			\WP_CLI::warning( 'No products found with warehouse stock metas. Run "wp wms sync" first.' );
			return;
		}

		\WP_CLI::log(
			sprintf(
				'Found %d product/variation IDs (%d variations).',
				$total,
				count( $variation_ids )
			)
		);

		// ── Phase 2: process in batches ────────────────────────────────────────
		$variation_set   = array_flip( $variation_ids );
		$progress        = \WP_CLI\Utils\make_progress_bar( 'Updating WooCommerce stock', $total );
		$total_processed = 0;
		$total_updated   = 0;
		$offset          = 0;

		while ( $offset < $total ) {
			$batch = array_slice( $all_ids, $offset, self::BATCH_SIZE );
			$stats = $updater->process_ids( $batch, $variation_set );

			$total_processed += $stats['processed'];
			$total_updated   += $stats['updated'];

			for ( $i = 0; $i < $stats['processed']; $i++ ) {
				$progress->tick();
			}

			$offset += count( $batch );
		}

		$progress->finish();

		\WP_CLI::success(
			sprintf(
				'Sync total complete — processed: %d, updated: %d.',
				$total_processed,
				$total_updated
			)
		);
	}

	// ── Private helpers ───────────────────────────────────────────────────────

	/**
	 * Resolve a --warehouse argument to an array of warehouse maps.
	 *
	 * @param string            $warehouse_id  Warehouse slug, or 'all'.
	 * @param Warehouse_Manager $wm            Warehouse manager instance.
	 * @return array  Array of warehouse maps; empty array if ID is not found.
	 */
	private function resolve_warehouses( string $warehouse_id, Warehouse_Manager $wm ): array {
		if ( 'all' === $warehouse_id ) {
			return $wm->get_all();
		}

		$warehouse = $wm->get_by_id( $warehouse_id );

		if ( false === $warehouse ) {
			\WP_CLI::warning(
				sprintf(
					'Warehouse ID "%s" not found. Use --warehouse=all or check the plugin settings.',
					$warehouse_id
				)
			);
			return array();
		}

		return array( $warehouse );
	}
}
