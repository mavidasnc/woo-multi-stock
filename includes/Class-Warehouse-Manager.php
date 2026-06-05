<?php
/**
 * Class Warehouse_Manager
 *
 * Single source of truth for all warehouse configuration:
 *  - CRUD on the `woo_multi_stock_warehouses` option (a serialised array).
 *  - Lazy migration from the legacy single-warehouse options.
 *  - Derivation of the per-warehouse meta key  (_stock_{LABEL})
 *    and the transient key (wms_csv_{id}).
 *  - AJAX handler for saving the warehouses list from the admin UI.
 *
 * DATA SHAPE
 * ──────────
 * get_option('woo_multi_stock_warehouses') returns an array of maps:
 *   [
 *     [ 'id' => 'cmt', 'label' => 'CMT', 'csv_url' => 'https://...' ],
 *     [ 'id' => 'wh2', 'label' => 'WH2', 'csv_url' => 'https://...' ],
 *   ]
 *
 * 'id'      is generated once from the label via sanitize_title() and is
 *            NEVER changed afterwards — it determines the transient key.
 * 'label'   may be changed by the user; it determines the meta key.
 * 'csv_url' is the remote CSV endpoint for this warehouse.
 *
 * META KEY DERIVATION
 * ───────────────────
 * Warehouse_Manager::get_meta_key( ['label' => 'CMT'] ) → '_stock_CMT'
 * Warehouse_Manager::get_meta_key( ['label' => 'WH 2'] ) → '_stock_WH2'
 * Rule: '_stock_' . preg_replace( '/[^A-Za-z0-9]/', '', $label )
 *
 * LEGACY MIGRATION
 * ────────────────
 * If 'woo_multi_stock_warehouses' is absent but the legacy options
 * 'woo_multi_stock_warehouse_name' and/or 'woo_multi_stock_csv_url' exist,
 * maybe_migrate() auto-creates the new option on first admin load.
 * Legacy options are left in the DB (not deleted) for external consumers.
 *
 * @package WooMultiStock
 */

namespace WooMultiStock;

// Prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Warehouse_Manager
 *
 * Manages the multi-warehouse configuration option and exposes helpers
 * consumed by Processor, Total_Updater, Stock_Table, and Admin.
 */
class Warehouse_Manager {

	// ── Option keys ───────────────────────────────────────────────────────────

	/** @var string wp_options key for the warehouses array. */
	public const OPTION_KEY = 'woo_multi_stock_warehouses';

	/** @var string wp_options key for the force-backorders global toggle. */
	public const OPTION_FORCE_BACKORDERS = 'woo_multi_stock_force_backorders';

	/** @var string Legacy single-warehouse name option (read-only after migration). */
	private const LEGACY_OPT_NAME = 'woo_multi_stock_warehouse_name';

	/** @var string Legacy single-warehouse URL option (read-only after migration). */
	private const LEGACY_OPT_URL = 'woo_multi_stock_csv_url';

	// ── AJAX action ───────────────────────────────────────────────────────────

	/** @var string Action name for the save-warehouses AJAX call. */
	public const AJAX_SAVE = 'wms_save_warehouses';

	// ── Public API ────────────────────────────────────────────────────────────

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'wp_ajax_' . self::AJAX_SAVE, array( $this, 'handle_ajax_save' ) );
	}

	/**
	 * Migrate legacy single-warehouse options to the new multi-warehouse format.
	 *
	 * Called once from the main plugin file's plugins_loaded callback (before
	 * any class that reads warehouses). Safe to call on every request because
	 * the option existence check makes it a no-op after the first migration.
	 *
	 * @return void
	 */
	public function maybe_migrate(): void {
		// Already migrated (or intentionally empty) — nothing to do.
		if ( false !== get_option( self::OPTION_KEY ) ) {
			return;
		}

		// Read legacy values (fall back to sensible defaults so the plugin
		// is usable even if the old options were never set).
		$legacy_name = get_option( self::LEGACY_OPT_NAME, 'CMT' );
		$legacy_url  = get_option( self::LEGACY_OPT_URL, '' );

		// Build the first warehouse entry from the legacy data.
		$label = sanitize_text_field( (string) $legacy_name );
		if ( '' === $label ) {
			$label = 'CMT';
		}

		$warehouses = array(
			array(
				'id'      => sanitize_title( $label ),
				'label'   => $label,
				'csv_url' => esc_url_raw( (string) $legacy_url ),
			),
		);

		update_option( self::OPTION_KEY, $warehouses, false );
	}

	/**
	 * Return all configured warehouses.
	 *
	 * Always returns an array (never false), even if the option is missing or
	 * corrupt. Each element is guaranteed to have 'id', 'label', 'csv_url'.
	 *
	 * @return array
	 */
	public function get_all(): array {
		$raw = get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $raw ) ) {
			return array();
		}

		// Filter out any entry that is not a well-formed array.
		$result = array();
		foreach ( $raw as $wh ) {
			if ( is_array( $wh )
				&& ! empty( $wh['id'] )
				&& ! empty( $wh['label'] )
				&& isset( $wh['csv_url'] ) ) {
				$result[] = $wh;
			}
		}

		return $result;
	}

	/**
	 * Find a warehouse by its stable id slug.
	 *
	 * @param string $id  The warehouse id (e.g. 'cmt').
	 * @return array|false  The warehouse array, or false if not found.
	 */
	public function get_by_id( string $id ) {
		foreach ( $this->get_all() as $wh ) {
			if ( $wh['id'] === $id ) {
				return $wh;
			}
		}
		return false;
	}

	/**
	 * Persist a validated array of warehouses.
	 *
	 * @param array $warehouses  Array of warehouse maps (already sanitised).
	 * @return bool  True on success.
	 */
	public function save_all( array $warehouses ): bool {
		return (bool) update_option( self::OPTION_KEY, $warehouses, false );
	}

	/**
	 * Return whether the force-backorders toggle is currently active.
	 *
	 * Static so Backorder_Manager can call it without instantiating this class
	 * (avoids a second object allocation on every frontend request).
	 *
	 * Default is 'no' (off) — the toggle must be explicitly enabled in the admin.
	 *
	 * @return bool
	 */
	public static function force_backorders(): bool {
		return 'yes' === get_option( self::OPTION_FORCE_BACKORDERS, 'no' );
	}

	// ── Static helpers (used by Processor, Total_Updater, Stock_Table) ─────────

	/**
	 * Derive the meta key for a warehouse.
	 *
	 * Rule: '_stock_' + label stripped of everything that is not a letter or
	 * digit. This preserves the original casing so 'CMT' → '_stock_CMT'
	 * (backward-compatible with all existing postmeta rows).
	 *
	 * @param array $warehouse  A single warehouse map from get_all().
	 * @return string  e.g. '_stock_CMT'
	 */
	public static function get_meta_key( array $warehouse ): string {
		$safe = preg_replace( '/[^A-Za-z0-9]/', '', $warehouse['label'] );
		return '_stock_' . $safe;
	}

	/**
	 * Derive the transient key for a warehouse sync session.
	 *
	 * Using a short prefix keeps us well under WordPress's 172-char limit.
	 *
	 * @param array $warehouse  A single warehouse map from get_all().
	 * @return string  e.g. 'wms_csv_cmt'
	 */
	public static function get_transient_key( array $warehouse ): string {
		return 'wms_csv_' . $warehouse['id'];
	}

	// ── AJAX handler ──────────────────────────────────────────────────────────

	/**
	 * AJAX: save the warehouses list submitted from the admin form.
	 *
	 * Expected POST body:
	 *   nonce       — wp_ajax nonce (Admin::NONCE_ACTION)
	 *   warehouses  — JSON-encoded array of {id,label,csv_url}
	 *
	 * @return void  (exits via wp_send_json_*)
	 */
	public function handle_ajax_save(): void {
		// ── Security ──────────────────────────────────────────────────────────
		check_ajax_referer( Admin::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				__( 'You do not have permission to perform this action.', 'woo-multi-stock' ),
				403
			);
		}

		// ── Input ─────────────────────────────────────────────────────────────
		// The JS sends a JSON-encoded string in the 'warehouses' field.
		$raw_json = isset( $_POST['warehouses'] ) ? wp_unslash( $_POST['warehouses'] ) : '[]'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw      = json_decode( $raw_json, true );

		if ( ! is_array( $raw ) ) {
			wp_send_json_error(
				__( 'Invalid warehouse data received.', 'woo-multi-stock' ),
				400
			);
		}

		// ── Sanitise ──────────────────────────────────────────────────────────
		$warehouses = array();
		$seen_ids   = array();

		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$label   = sanitize_text_field( (string) ( $entry['label']   ?? '' ) );
			$csv_url = esc_url_raw( (string) ( $entry['csv_url'] ?? '' ) );

			// Label is required.
			if ( '' === $label ) {
				continue;
			}

			// Use the provided id if it's a valid slug; otherwise derive it
			// from the label. This preserves existing ids when editing.
			$id_raw = isset( $entry['id'] ) ? sanitize_title( (string) $entry['id'] ) : '';
			$id     = '' !== $id_raw ? $id_raw : sanitize_title( $label );

			// Deduplicate ids within this save operation.
			if ( in_array( $id, $seen_ids, true ) ) {
				// Append a numeric suffix to make the id unique.
				$suffix = 2;
				while ( in_array( $id . '_' . $suffix, $seen_ids, true ) ) {
					$suffix++;
				}
				$id = $id . '_' . $suffix;
			}
			$seen_ids[] = $id;

			$warehouses[] = array(
				'id'      => $id,
				'label'   => $label,
				'csv_url' => $csv_url,
			);
		}

		// ── Persist ───────────────────────────────────────────────────────────
		$this->save_all( $warehouses );

		// Save the force-backorders toggle alongside the warehouses.
		// Nonce and capability are already verified above.
		// JS sends '1' (checked) or '0' (unchecked) — strict comparison is the sanitization.
		$force_backorders = isset( $_POST['force_backorders'] ) && '1' === (string) wp_unslash( $_POST['force_backorders'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		update_option( self::OPTION_FORCE_BACKORDERS, $force_backorders ? 'yes' : 'no', false );

		wp_send_json_success( array( 'warehouses' => $warehouses ) );
	}
}
