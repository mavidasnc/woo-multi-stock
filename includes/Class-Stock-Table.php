<?php
/**
 * Class Stock_Table
 *
 * Serves the paginated stock overview table via AJAX.
 *
 * AJAX action: wms_stock_table_fetch
 * POST params: nonce, page (int ≥ 1), search_sku (string, optional)
 *
 * Response JSON:
 * {
 *   rows: [
 *     { id: 123, sku: "01.02", name: "Product A", wc_stock: 10,
 *       warehouses: { CMT: 8, WH2: 2 } }
 *   ],
 *   total_rows:       5200,
 *   total_pages:      104,
 *   current_page:     1,
 *   warehouse_labels: ["CMT","WH2"]
 * }
 *
 * PERFORMANCE
 * ───────────
 * With ~5200 variations, naive row-by-row queries would be catastrophic.
 * This class uses two queries per page:
 *  1. WP_Query with 'fields' => 'ids' — returns only post IDs for the
 *     current page. No WP_Post objects are loaded.
 *  2. One raw SQL query on postmeta for all IDs in the current batch,
 *     fetching only the meta keys we care about (_sku, _stock, _stock_*).
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

		$page       = max( 1, absint( isset( $_POST['page'] ) ? $_POST['page'] : 1 ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$search_sku = isset( $_POST['search_sku'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			? sanitize_text_field( wp_unslash( (string) $_POST['search_sku'] ) )
			: '';

		// Get warehouse labels for the response header.
		$wm               = new Warehouse_Manager();
		$warehouses       = $wm->get_all();
		$warehouse_labels = array();
		foreach ( $warehouses as $wh ) {
			$warehouse_labels[] = $wh['label'];
		}

		// Query product/variation IDs for this page.
		$query_result = $this->query_products( $page, $search_sku );
		$post_ids     = $query_result['ids'];
		$total_rows   = $query_result['total'];
		$total_pages  = $query_result['pages'];

		// Build rows with a single batched meta query.
		$rows = empty( $post_ids )
			? array()
			: $this->build_rows( $post_ids, $warehouse_labels );

		wp_send_json_success(
			array(
				'rows'             => $rows,
				'total_rows'       => $total_rows,
				'total_pages'      => $total_pages,
				'current_page'     => $page,
				'warehouse_labels' => $warehouse_labels,
			)
		);
	}

	// ── Private helpers ───────────────────────────────────────────────────────

	/**
	 * Run a WP_Query for product and variation IDs.
	 *
	 * Uses 'fields' => 'ids' to avoid loading WP_Post objects.
	 * SKU search is done via a postmeta LIKE query which is acceptable for
	 * ~5200 rows — the postmeta table is indexed on (post_id, meta_key).
	 *
	 * @param int    $page        1-based page number.
	 * @param string $search_sku  Optional SKU substring to filter by.
	 * @return array { ids: int[], total: int, pages: int }
	 */
	private function query_products( int $page, string $search_sku ): array {
		$args = array(
			'post_type'      => array( 'product', 'product_variation' ),
			'post_status'    => array( 'publish', 'private' ),
			'fields'         => 'ids',
			'posts_per_page' => self::PAGE_SIZE,
			'paged'          => $page,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'no_found_rows'  => false,
		);

		if ( '' !== $search_sku ) {
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => '_sku',
					'value'   => $search_sku,
					'compare' => 'LIKE',
				),
			);
		}

		$query = new \WP_Query( $args );

		$ids   = is_array( $query->posts ) ? array_map( 'intval', $query->posts ) : array();
		$total = (int) $query->found_posts;
		$pages = (int) $query->max_num_pages;

		if ( 0 === $pages && $total > 0 ) {
			$pages = 1;
		}

		return array(
			'ids'   => $ids,
			'total' => $total,
			'pages' => $pages,
		);
	}

	/**
	 * Build table row data for the given post IDs using a single SQL query.
	 *
	 * Fetches _sku, _stock, and all _stock_{LABEL} meta keys in one round
	 * trip to avoid N+1 queries. The warehouse_labels array tells us which
	 * specific keys to include in the per-row "warehouses" map.
	 *
	 * @param int[]    $post_ids         Post IDs to build rows for.
	 * @param string[] $warehouse_labels Labels of configured warehouses.
	 * @return array[]  Each element: { id, sku, name, wc_stock, warehouses }.
	 */
	private function build_rows( array $post_ids, array $warehouse_labels ): array {
		global $wpdb;

		// Build the list of meta keys we need.
		$meta_keys = array( '_sku', '_stock' );
		foreach ( $warehouse_labels as $label ) {
			$safe       = preg_replace( '/[^A-Za-z0-9]/', '', $label );
			$meta_keys[] = '_stock_' . $safe;
		}
		$meta_keys = array_unique( $meta_keys );

		// Build placeholders.
		$id_placeholders  = implode( ',', array_map( 'intval', $post_ids ) );
		$key_placeholders = implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$sql  = $wpdb->prepare(
			"SELECT post_id, meta_key, meta_value
			 FROM {$wpdb->postmeta}
			 WHERE post_id IN ({$id_placeholders})
			   AND meta_key IN ({$key_placeholders})",
			...$meta_keys
		);
		$meta_rows = $wpdb->get_results( $sql, ARRAY_A );
		// phpcs:enable

		// Index by post_id → key → value.
		$meta_index = array();
		if ( is_array( $meta_rows ) ) {
			foreach ( $meta_rows as $mr ) {
				$meta_index[ (int) $mr['post_id'] ][ $mr['meta_key'] ] = $mr['meta_value'];
			}
		}

		// Fetch post titles in bulk using get_posts.
		$posts = get_posts(
			array(
				'post__in'       => $post_ids,
				'post_type'      => array( 'product', 'product_variation' ),
				'post_status'    => 'any',
				'posts_per_page' => count( $post_ids ),
				'orderby'        => 'post__in',
			)
		);

		$title_index = array();
		foreach ( $posts as $p ) {
			$title_index[ $p->ID ] = $p->post_title;
		}

		// Assemble rows in the original query order.
		$rows = array();
		foreach ( $post_ids as $id ) {
			$meta      = isset( $meta_index[ $id ] ) ? $meta_index[ $id ] : array();
			$warehouses = array();

			foreach ( $warehouse_labels as $label ) {
				$safe                  = preg_replace( '/[^A-Za-z0-9]/', '', $label );
				$wh_key                = '_stock_' . $safe;
				$warehouses[ $label ] = isset( $meta[ $wh_key ] ) ? (int) $meta[ $wh_key ] : 0;
			}

			$rows[] = array(
				'id'         => $id,
				'sku'        => isset( $meta['_sku'] ) ? $meta['_sku'] : '',
				'name'       => isset( $title_index[ $id ] ) ? $title_index[ $id ] : '',
				'wc_stock'   => isset( $meta['_stock'] ) ? (int) $meta['_stock'] : 0,
				'warehouses' => $warehouses,
			);
		}

		return $rows;
	}
}
