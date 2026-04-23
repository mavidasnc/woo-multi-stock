<?php
/**
 * Class Stock_Table
 *
 * Serves the paginated stock overview table via AJAX.
 *
 * AJAX action: wms_stock_table_fetch
 * POST params: nonce, page (int ≥ 1), search_sku (string, optional),
 *              warehouse_filter (string, optional warehouse id)
 *
 * Response JSON:
 * {
 *   rows: [
 *     { id: 123, sku: "01.02", parent_sku: "01", name: "Variation A",
 *       wc_stock: 10, warehouses: { CMT: 8, WH2: 2 } }
 *   ],
 *   total_rows:       5200,
 *   total_pages:      104,
 *   current_page:     1,
 *   warehouse_labels: ["CMT","WH2"]
 * }
 *
 * PERFORMANCE
 * ───────────
 * Two queries per page (down from three in the previous WP_Query approach):
 *  1. One raw SQL query that returns IDs, titles, SKUs, and parent SKUs for
 *     the current page. LEFT JOINs on postmeta for _sku and parent _sku allow
 *     searching both in a single pass. INNER JOIN replaces the meta_query
 *     warehouse filter, avoiding a second WP_Query.
 *  2. One batched postmeta query fetching _stock and all _stock_* keys for
 *     the page's IDs.
 *
 * Searching by parent SKU is supported: the WHERE clause ORs over both
 * the variation's own _sku and the parent post's _sku, so entering a
 * parent SKU returns all its variations.
 *
 * @package WooMultiStock
 */

namespace WooMultiStock;

// Prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Stock_Table
 *
 * Server-side paginated stock table for the admin overview.
 */
class Stock_Table {

	/** @var int Rows returned per page. */
	private const PAGE_SIZE = 50;

	/** @var bool|null Cached WPML availability flag. */
	private static $wpml_available = null;

	// ── Public API ────────────────────────────────────────────────────────────

	/**
	 * Register the AJAX hook.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'wp_ajax_wms_stock_table_fetch', array( $this, 'handle_fetch_page' ) );
	}

	// ── AJAX handler ──────────────────────────────────────────────────────────

	/**
	 * AJAX handler: fetch one page of the stock table.
	 *
	 * @return void
	 */
	public function handle_fetch_page(): void {
		check_ajax_referer( Admin::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				__( 'You do not have permission to perform this action.', 'woo-multi-stock' ),
				403
			);
		}

		$page             = max( 1, absint( isset( $_POST['page'] ) ? $_POST['page'] : 1 ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$search_sku       = isset( $_POST['search_sku'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			? sanitize_text_field( wp_unslash( (string) $_POST['search_sku'] ) )
			: '';
		$warehouse_filter = isset( $_POST['warehouse_filter'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			? sanitize_title( wp_unslash( (string) $_POST['warehouse_filter'] ) )
			: '';

		$wm               = new Warehouse_Manager();
		$warehouses       = $wm->get_all();
		$warehouse_labels = array();
		foreach ( $warehouses as $wh ) {
			$warehouse_labels[] = $wh['label'];
		}

		$wpml_active = self::is_wpml_available();

		// Single SQL query: count + page rows (with parent_sku and optional language).
		$result    = $this->query_table_page( $page, $search_sku, $warehouse_filter, $wpml_active );
		$post_ids  = $result['post_ids'];
		$base_rows = $result['base_rows'];
		$total     = $result['total'];
		$pages     = $result['pages'];

		// Enrich with _stock and per-warehouse metas (one batched query).
		$rows = empty( $post_ids )
			? array()
			: $this->enrich_rows_with_stock( $post_ids, $base_rows, $warehouse_labels );

		wp_send_json_success(
			array(
				'rows'             => $rows,
				'total_rows'       => $total,
				'total_pages'      => $pages,
				'current_page'     => $page,
				'warehouse_labels' => $warehouse_labels,
				'wpml_active'      => $wpml_active,
			)
		);
	}

	/**
	 * Detect whether WPML's translation table is present in this install.
	 *
	 * Checks the actual DB table rather than class/constant existence so the
	 * result stays correct even if WPML is temporarily deactivated but the
	 * translation data is still in place.
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

	// ── Private helpers ───────────────────────────────────────────────────────

	/**
	 * Run a unified SQL query for one table page.
	 *
	 * Returns IDs, titles, SKUs, and parent SKUs in a single round trip,
	 * eliminating the WP_Query + get_posts two-step of the previous version.
	 *
	 * Search matches either the row's own _sku or the parent post's _sku, so
	 * entering a variable product SKU returns all its child variations.
	 *
	 * @param int    $page             1-based page number.
	 * @param string $search_sku       Optional SKU substring to filter by.
	 * @param string $warehouse_filter Optional warehouse id — only rows with qty > 0 in that warehouse.
	 * @param bool   $wpml_active      When true, LEFT JOIN icl_translations and SELECT language_code.
	 * @return array {
	 *   @type int    total     Total matching rows (for pagination).
	 *   @type int    pages     Total pages.
	 *   @type int[]  post_ids  IDs in page order.
	 *   @type array  base_rows Keyed by ID: { id, name, sku, parent_sku, language, wc_stock:0, warehouses:[] }.
	 * }
	 */
	private function query_table_page( int $page, string $search_sku, string $warehouse_filter, bool $wpml_active ): array {
		global $wpdb;

		$offset    = ( $page - 1 ) * self::PAGE_SIZE;

		// Hide variable product parents: any post_type='product' that has at least
		// one child variation is redundant in the stock view since its children
		// carry the actual per-variation stock. Simple products have no such
		// children and pass through.
		$where_sql = "p.post_type IN ('product','product_variation')
		              AND p.post_status IN ('publish','private')
		              AND NOT EXISTS (
		                  SELECT 1 FROM {$wpdb->posts} c
		                  WHERE c.post_parent = p.ID
		                    AND c.post_type   = 'product_variation'
		                    AND c.post_status NOT IN ('trash','auto-draft')
		              )";

		$params    = array();
		$wh_join   = '';
		$lang_select = '';
		$lang_join   = '';

		if ( $wpml_active ) {
			$lang_select = ", tr.language_code AS language";
			$lang_join   = "LEFT JOIN {$wpdb->prefix}icl_translations tr
			                ON tr.element_id   = p.ID
			                AND tr.element_type IN ('post_product','post_product_variation')";
		}

		// ── Optional warehouse filter via INNER JOIN ───────────────────────────
		if ( '' !== $warehouse_filter ) {
			$wm        = new Warehouse_Manager();
			$warehouse = $wm->get_by_id( $warehouse_filter );
			if ( false !== $warehouse ) {
				$wh_meta_key = Warehouse_Manager::get_meta_key( $warehouse );
				// Meta key is _stock_[A-Za-z0-9]+ — esc_sql is sufficient.
				$wh_join = "INNER JOIN {$wpdb->postmeta} whf
				            ON whf.post_id = p.ID
				            AND whf.meta_key = '" . esc_sql( $wh_meta_key ) . "'
				            AND CAST(whf.meta_value AS SIGNED) > 0";
			}
		}

		// ── Optional SKU search (own SKU OR parent SKU) ───────────────────────
		if ( '' !== $search_sku ) {
			$like       = '%' . $wpdb->esc_like( $search_sku ) . '%';
			$where_sql .= ' AND ( sku.meta_value LIKE %s OR psku.meta_value LIKE %s )';
			$params[]   = $like;
			$params[]   = $like;
		}

		// Shared FROM + JOINs fragment reused in both COUNT and SELECT queries.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql_from = "FROM {$wpdb->posts} p
		             LEFT JOIN {$wpdb->postmeta} sku
		               ON sku.post_id = p.ID AND sku.meta_key = '_sku'
		             LEFT JOIN {$wpdb->postmeta} psku
		               ON psku.post_id = p.post_parent AND psku.meta_key = '_sku'
		             {$lang_join}
		             {$wh_join}
		             WHERE {$where_sql}";

		// ── COUNT ─────────────────────────────────────────────────────────────
		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		$count_sql = "SELECT COUNT(*) {$sql_from}";
		if ( empty( $params ) ) {
			$total = (int) $wpdb->get_var( $count_sql );
		} else {
			$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$params ) );
		}
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

		$pages = ( $total > 0 ) ? (int) ceil( $total / self::PAGE_SIZE ) : 0;

		if ( 0 === $total ) {
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			return array( 'total' => 0, 'pages' => 0, 'post_ids' => array(), 'base_rows' => array() );
		}

		// ── SELECT page rows ──────────────────────────────────────────────────
		$select_params = array_merge( $params, array( self::PAGE_SIZE, $offset ) );
		$rows_sql      = $wpdb->prepare(
			"SELECT p.ID, p.post_type, p.post_title,
			        sku.meta_value  AS sku,
			        psku.meta_value AS parent_sku
			        {$lang_select}
			 {$sql_from}
			 ORDER BY p.ID ASC
			 LIMIT %d OFFSET %d",
			...$select_params
		);
		$page_rows = $wpdb->get_results( $rows_sql, ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery

		$post_ids  = array();
		$base_rows = array();

		if ( is_array( $page_rows ) ) {
			foreach ( $page_rows as $r ) {
				$id         = (int) $r['ID'];
				$post_ids[] = $id;

				$base_rows[ $id ] = array(
					'id'         => $id,
					'name'       => (string) $r['post_title'],
					'sku'        => isset( $r['sku'] ) ? (string) $r['sku'] : '',
					'parent_sku' => 'product_variation' === $r['post_type']
					                ? ( isset( $r['parent_sku'] ) ? (string) $r['parent_sku'] : '' )
					                : '',
					'language'   => isset( $r['language'] ) ? strtoupper( (string) $r['language'] ) : '',
					'wc_stock'   => 0,
					'warehouses' => array(),
				);
			}
		}

		return array(
			'total'     => $total,
			'pages'     => $pages,
			'post_ids'  => $post_ids,
			'base_rows' => $base_rows,
		);
	}

	/**
	 * Enrich base row data with _stock and per-warehouse meta values.
	 *
	 * Uses a single batched postmeta query for all IDs on the page.
	 *
	 * @param int[]    $post_ids         IDs in display order.
	 * @param array[]  $base_rows        Keyed by ID, from query_table_page().
	 * @param string[] $warehouse_labels Configured warehouse labels.
	 * @return array[]  Complete row maps ready for JSON output.
	 */
	private function enrich_rows_with_stock( array $post_ids, array $base_rows, array $warehouse_labels ): array {
		global $wpdb;

		$meta_keys = array( '_stock' );
		foreach ( $warehouse_labels as $label ) {
			$safe        = preg_replace( '/[^A-Za-z0-9]/', '', $label );
			$meta_keys[] = '_stock_' . $safe;
		}
		$meta_keys = array_unique( $meta_keys );

		$id_placeholders  = implode( ',', array_map( 'intval', $post_ids ) );
		$key_placeholders = implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$sql       = $wpdb->prepare(
			"SELECT post_id, meta_key, meta_value
			 FROM {$wpdb->postmeta}
			 WHERE post_id IN ({$id_placeholders})
			   AND meta_key IN ({$key_placeholders})",
			...$meta_keys
		);
		$meta_rows = $wpdb->get_results( $sql, ARRAY_A );
		// phpcs:enable

		$meta_index = array();
		if ( is_array( $meta_rows ) ) {
			foreach ( $meta_rows as $mr ) {
				$meta_index[ (int) $mr['post_id'] ][ $mr['meta_key'] ] = $mr['meta_value'];
			}
		}

		$rows = array();
		foreach ( $post_ids as $id ) {
			$base       = isset( $base_rows[ $id ] ) ? $base_rows[ $id ] : array();
			$meta       = isset( $meta_index[ $id ] ) ? $meta_index[ $id ] : array();
			$warehouses = array();

			foreach ( $warehouse_labels as $label ) {
				$safe                 = preg_replace( '/[^A-Za-z0-9]/', '', $label );
				$wh_key               = '_stock_' . $safe;
				$warehouses[ $label ] = isset( $meta[ $wh_key ] ) ? (int) $meta[ $wh_key ] : 0;
			}

			$base['wc_stock']   = isset( $meta['_stock'] ) ? (int) $meta['_stock'] : 0;
			$base['warehouses'] = $warehouses;

			$rows[] = $base;
		}

		return $rows;
	}
}
