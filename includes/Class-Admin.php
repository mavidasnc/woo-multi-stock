<?php
/**
 * Class Admin
 *
 * Responsible for everything visible in the WordPress admin:
 *  - Registering the submenu page under "WooCommerce".
 *  - Enqueueing admin-script.js and localising the wmsData JS object.
 *  - Rendering the multi-warehouse management UI and sync interface.
 *
 * UI structure (three sections):
 *  A) Warehouse management — dynamic table: add / edit / remove warehouses,
 *     save to `woo_multi_stock_warehouses` via AJAX.
 *  B) Sync — one block per warehouse (per-warehouse Sync button) plus a
 *     global "Sync All → WC Stock" button (Total_Updater).
 *  C) Stock overview — paginated AJAX table: SKU | Name | WC Stock | [wh cols]
 *     with live SKU search filter.
 *
 * @package WooMultiStock
 */

namespace WooMultiStock;

// Prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Admin
 *
 * Handles the plugin's admin UI: menu page, asset enqueueing, and HTML rendering.
 */
class Admin {

	// ── AJAX / nonce ──────────────────────────────────────────────────────────

	/**
	 * The nonce action string shared between this class and all AJAX handlers.
	 *
	 * @var string
	 */
	public const NONCE_ACTION = 'woo_multi_stock_ajax_nonce';

	/** @var string Menu slug for the admin page. */
	private const PAGE_SLUG = 'woo-multi-stock';

	// ── Public API ────────────────────────────────────────────────────────────

	/**
	 * Register all WordPress hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu',            array( $this, 'add_menu_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	// ── Hook callbacks ────────────────────────────────────────────────────────

	/**
	 * Add a submenu page under the "WooCommerce" top-level menu.
	 *
	 * @return void
	 */
	public function add_menu_page(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Multi Stock Sync', 'woo-multi-stock' ),
			__( 'Multi Stock', 'woo-multi-stock' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue the plugin's admin script and pass PHP data to JavaScript.
	 *
	 * @param string $hook_suffix  Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( false === strpos( $hook_suffix, self::PAGE_SLUG ) ) {
			return;
		}

		wp_enqueue_script(
			'woo-multi-stock-admin',
			WMS_PLUGIN_URL . 'assets/admin-script.js',
			array( 'jquery' ),
			WMS_VERSION,
			true
		);

		$wm         = new Warehouse_Manager();
		$warehouses = $wm->get_all();

		wp_localize_script(
			'woo-multi-stock-admin',
			'wmsData',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( self::NONCE_ACTION ),
				'batchSize'  => 50,
				'warehouses' => $warehouses,
				'i18n'       => array(
					// Per-warehouse sync.
					'startSync'     => __( 'Start Sync', 'woo-multi-stock' ),
					'syncing'       => __( 'Syncing…', 'woo-multi-stock' ),
					'done'          => __( 'Sync complete.', 'woo-multi-stock' ),
					'errorDownload' => __( 'Failed to download CSV. Check the URL in settings.', 'woo-multi-stock' ),
					'errorBatch'    => __( 'An error occurred during batch processing. Please retry.', 'woo-multi-stock' ),
					/* translators: 1: processed count  2: not-found count  3: updated count */
					'summaryTpl'    => __( 'Processed %1$d products, %2$d SKUs not found, %3$d SKUs updated.', 'woo-multi-stock' ),
					// Sync All.
					'syncAll'       => __( 'Sync All → WC Stock', 'woo-multi-stock' ),
					'calculating'   => __( 'Calculating…', 'woo-multi-stock' ),
					'calcDone'      => __( 'WooCommerce stock updated.', 'woo-multi-stock' ),
					'errorCalc'     => __( 'An error occurred during stock aggregation. Please retry.', 'woo-multi-stock' ),
					/* translators: 1: updated count */
					'calcSummary'   => __( '%1$d products updated.', 'woo-multi-stock' ),
					// Warehouse management.
					'saveWarehouses'  => __( 'Save configuration', 'woo-multi-stock' ),
					'addWarehouse'    => __( 'Add warehouse', 'woo-multi-stock' ),
					'removeWarehouse' => __( 'Remove', 'woo-multi-stock' ),
					'savedOk'         => __( 'Configuration saved.', 'woo-multi-stock' ),
					'savedError'      => __( 'Error saving configuration.', 'woo-multi-stock' ),
					// Stock table.
					'searchSku'    => __( 'Filter by SKU…', 'woo-multi-stock' ),
					'search'       => __( 'Search', 'woo-multi-stock' ),
					'loading'      => __( 'Loading…', 'woo-multi-stock' ),
					'noResults'    => __( 'No products found.', 'woo-multi-stock' ),
					/* translators: 1: current page  2: total pages */
					'pageInfo'     => __( 'Page %1$d of %2$d', 'woo-multi-stock' ),
					'prevPage'      => __( '◄ Prev', 'woo-multi-stock' ),
					'nextPage'      => __( 'Next ►', 'woo-multi-stock' ),
					'allWarehouses' => __( 'All warehouses', 'woo-multi-stock' ),
				),
			)
		);
	}

	// ── Page rendering ────────────────────────────────────────────────────────

	/**
	 * Render the full admin page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'woo-multi-stock' ), 403 );
		}

		$wm         = new Warehouse_Manager();
		$warehouses = $wm->get_all();
		?>
		<div class="wrap">

			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<?php /* ── Section A: Warehouse management ─────────────────────── */ ?>
			<h2><?php esc_html_e( 'Warehouse Configuration', 'woo-multi-stock' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Add, edit, or remove warehouses. Each warehouse has a label (used for the meta key) and a remote CSV URL. Changes are saved immediately via the "Save configuration" button.', 'woo-multi-stock' ); ?>
			</p>

			<table class="wp-list-table widefat fixed striped" id="wms-warehouses-table">
				<thead>
					<tr>
						<th style="width:22%"><?php esc_html_e( 'Name (label)', 'woo-multi-stock' ); ?></th>
						<th style="width:18%"><?php esc_html_e( 'Meta key', 'woo-multi-stock' ); ?></th>
						<th><?php esc_html_e( 'CSV Remote URL', 'woo-multi-stock' ); ?></th>
						<th style="width:10%"><?php esc_html_e( 'Actions', 'woo-multi-stock' ); ?></th>
					</tr>
				</thead>
				<tbody id="wms-warehouses-tbody">
				<?php foreach ( $warehouses as $wh ) : ?>
					<tr class="wms-wh-row" data-id="<?php echo esc_attr( $wh['id'] ); ?>">
						<td>
							<input
								type="text"
								class="regular-text wms-wh-label"
								value="<?php echo esc_attr( $wh['label'] ); ?>"
								placeholder="<?php esc_attr_e( 'e.g. CMT', 'woo-multi-stock' ); ?>"
							>
						</td>
						<td>
							<code class="wms-wh-meta-preview">
								<?php echo esc_html( Warehouse_Manager::get_meta_key( $wh ) ); ?>
							</code>
						</td>
						<td>
							<input
								type="url"
								class="regular-text wms-wh-url"
								value="<?php echo esc_attr( $wh['csv_url'] ); ?>"
								placeholder="https://example.com/stock.csv"
								style="width:100%"
							>
						</td>
						<td>
							<button type="button" class="button wms-remove-wh">
								<?php esc_html_e( 'Remove', 'woo-multi-stock' ); ?>
							</button>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<p style="margin-top:10px;">
				<button type="button" class="button" id="wms-add-warehouse">
					+ <?php esc_html_e( 'Add warehouse', 'woo-multi-stock' ); ?>
				</button>
				&nbsp;
				<button type="button" class="button button-primary" id="wms-save-warehouses">
					<?php esc_html_e( 'Save configuration', 'woo-multi-stock' ); ?>
				</button>
				<span id="wms-save-status" style="margin-left:10px;"></span>
			</p>

			<hr>

			<?php /* ── Section B: Sync ─────────────────────────────────────── */ ?>
			<h2><?php esc_html_e( 'Stock Synchronisation', 'woo-multi-stock' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Click a warehouse button to download its CSV and update the corresponding meta field. "Sync All → WC Stock" reads all existing warehouse metas and writes their sum to the native WooCommerce stock field.', 'woo-multi-stock' ); ?>
			</p>

			<div id="wms-sync-blocks">
			<?php foreach ( $warehouses as $wh ) : ?>
				<?php $meta_key = Warehouse_Manager::get_meta_key( $wh ); ?>
				<div class="wms-sync-block" data-id="<?php echo esc_attr( $wh['id'] ); ?>" style="margin-bottom:16px; padding:12px; background:#fff; border:1px solid #c3c4c7;">
					<strong><?php echo esc_html( $wh['label'] ); ?></strong>
					<code style="margin:0 8px;"><?php echo esc_html( $meta_key ); ?></code>
					<button type="button" class="button button-primary wms-sync-btn">
						<?php
						/* translators: %s: warehouse label */
						printf( esc_html__( 'Sync %s', 'woo-multi-stock' ), esc_html( $wh['label'] ) );
						?>
					</button>

					<div class="wms-progress-wrap" style="display:none; margin-top:10px;">
						<progress class="wms-progress-bar" value="0" max="100" style="width:100%; max-width:500px; height:18px;"></progress>
						<span class="wms-progress-text" style="margin-left:8px;">0%</span>
					</div>

					<div class="wms-counters" style="display:none; margin-top:6px; color:#555; font-size:13px;">
						<?php esc_html_e( 'Processed:', 'woo-multi-stock' ); ?>
						<strong class="wms-c-processed">0</strong>&nbsp;|&nbsp;
						<?php esc_html_e( 'Not found:', 'woo-multi-stock' ); ?>
						<strong class="wms-c-not-found">0</strong>&nbsp;|&nbsp;
						<?php esc_html_e( 'Updated:', 'woo-multi-stock' ); ?>
						<strong class="wms-c-updated">0</strong>
					</div>

					<div class="wms-sync-status" style="margin-top:8px;"></div>
				</div>
			<?php endforeach; ?>
			</div>

			<?php if ( empty( $warehouses ) ) : ?>
				<div class="notice notice-warning inline">
					<p><?php esc_html_e( 'No warehouses configured. Add at least one warehouse in the section above.', 'woo-multi-stock' ); ?></p>
				</div>
			<?php endif; ?>

			<p style="margin-top:12px;">
				<button type="button" class="button button-secondary" id="wms-sync-all" <?php disabled( empty( $warehouses ) ); ?>>
					<?php esc_html_e( 'Sync All → WC Stock', 'woo-multi-stock' ); ?>
				</button>
			</p>

			<div id="wms-syncall-wrap" style="display:none; margin-top:10px;">
				<progress id="wms-syncall-bar" value="0" max="100" style="width:100%; max-width:500px; height:18px;"></progress>
				<span id="wms-syncall-text" style="margin-left:8px;">0%</span>
				<div id="wms-syncall-status" style="margin-top:8px;"></div>
			</div>

			<hr>

			<?php /* ── Section C: Stock overview table ────────────────────── */ ?>
			<h2><?php esc_html_e( 'Stock Overview', 'woo-multi-stock' ); ?></h2>

			<p style="display:flex; flex-wrap:wrap; gap:8px; align-items:center;">
				<select id="wms-warehouse-filter">
					<option value=""><?php esc_html_e( 'All warehouses', 'woo-multi-stock' ); ?></option>
					<?php foreach ( $warehouses as $wh ) : ?>
						<option value="<?php echo esc_attr( $wh['id'] ); ?>">
							<?php echo esc_html( $wh['label'] ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<input
					type="text"
					id="wms-search-sku"
					class="regular-text"
					placeholder="<?php esc_attr_e( 'Filter by SKU…', 'woo-multi-stock' ); ?>"
					style="max-width:220px;"
				>
				<button type="button" class="button" id="wms-search-btn">
					<?php esc_html_e( 'Search', 'woo-multi-stock' ); ?>
				</button>
			</p>

			<table class="wp-list-table widefat fixed striped" id="wms-stock-table" style="margin-top:10px;">
				<thead id="wms-stock-thead">
					<tr>
						<th style="width:14%"><?php esc_html_e( 'SKU', 'woo-multi-stock' ); ?></th>
						<th><?php esc_html_e( 'Product / Variation', 'woo-multi-stock' ); ?></th>
						<th style="width:10%"><?php esc_html_e( 'WC Stock', 'woo-multi-stock' ); ?></th>
						<?php foreach ( $warehouses as $wh ) : ?>
							<th style="width:10%"><?php echo esc_html( $wh['label'] ); ?></th>
						<?php endforeach; ?>
					</tr>
				</thead>
				<tbody id="wms-stock-tbody">
					<tr>
						<td colspan="<?php echo 3 + count( $warehouses ); ?>" style="text-align:center;">
							<?php esc_html_e( 'Loading…', 'woo-multi-stock' ); ?>
						</td>
					</tr>
				</tbody>
			</table>

			<div id="wms-pagination" style="margin-top:10px; display:flex; align-items:center; gap:10px;">
				<button type="button" class="button" id="wms-prev-page" disabled>
					<?php esc_html_e( '◄ Prev', 'woo-multi-stock' ); ?>
				</button>
				<span id="wms-page-info"></span>
				<button type="button" class="button" id="wms-next-page" disabled>
					<?php esc_html_e( 'Next ►', 'woo-multi-stock' ); ?>
				</button>
			</div>

		</div><!-- .wrap -->
		<?php
	}
}
