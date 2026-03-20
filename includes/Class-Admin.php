<?php
/**
 * Class Admin
 *
 * Responsible for everything that is visible in the WordPress admin:
 *  - Registering the submenu page under "WooCommerce".
 *  - Wiring the WordPress Settings API (sections, fields, sanitisation).
 *  - Enqueueing admin-script.js and localising the wmsData JS object.
 *  - Rendering the settings form and the sync UI (progress bar, counters).
 *
 * This class has NO business logic. It only deals with presentation and
 * WordPress admin plumbing. All heavy lifting (CSV download, batch update)
 * lives in Class-Processor.php and Class-Stock-Updater.php.
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
 * Handles the plugin's admin UI: settings page, Settings API registration,
 * asset enqueueing, and the sync trigger interface.
 */
class Admin {

	// ── Option keys ──────────────────────────────────────────────────────────
	// Stored as two separate options (not a single serialised array) because:
	// 1. Each value has a different sanitisation callback.
	// 2. Individual get_option() calls are simpler and more readable.
	// 3. No risk of accidentally wiping both values on a sanitisation failure.

	/** @var string Option key for the warehouse/magazzino display name. */
	private const OPT_WAREHOUSE = 'woo_multi_stock_warehouse_name';

	/** @var string Option key for the remote CSV URL. */
	private const OPT_CSV_URL = 'woo_multi_stock_csv_url';

	// ── Settings API identifiers ──────────────────────────────────────────────
	// These strings must be consistent across register_setting(), settings_fields(),
	// add_settings_section(), add_settings_field(), and do_settings_sections().

	/** @var string The settings group passed to settings_fields() in the form. */
	private const SETTINGS_GROUP = 'woo_multi_stock_settings_group';

	/** @var string The page slug used as the menu slug and Settings API page key. */
	private const PAGE_SLUG = 'woo-multi-stock';

	/** @var string ID of the single settings section on our page. */
	private const SECTION_ID = 'woo_multi_stock_main_section';

	// ── AJAX / nonce ──────────────────────────────────────────────────────────

	/**
	 * The nonce action string shared between this class (where the nonce is
	 * created via wp_create_nonce) and Class-Processor.php (where it is
	 * verified via check_ajax_referer).
	 *
	 * @var string
	 */
	public const NONCE_ACTION = 'woo_multi_stock_ajax_nonce';

	// ── Public API ────────────────────────────────────────────────────────────

	/**
	 * Register all WordPress hooks needed by this class.
	 *
	 * Called once from the main plugin file's plugins_loaded callback.
	 * Using an explicit register_hooks() method (rather than hooking inside
	 * the constructor) keeps the class testable: you can instantiate it
	 * without triggering any side effects.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		// Add our submenu page to the WooCommerce menu.
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );

		// Register option names, sanitisation callbacks, and settings fields
		// with the Settings API. Must run on admin_init.
		add_action( 'admin_init', array( $this, 'register_settings' ) );

		// Enqueue our JavaScript only on the plugin's own admin page.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	// ── Hook callbacks (public so WordPress can call them) ────────────────────

	/**
	 * Add a submenu page under the "WooCommerce" top-level menu.
	 *
	 * add_submenu_page() returns the page's "hook suffix", which is the string
	 * we receive in the $hook_suffix parameter of admin_enqueue_scripts. We
	 * store it as a private property so enqueue_assets() can gate-keep loading.
	 *
	 * Capability: manage_options is the standard WordPress admin capability.
	 * WooCommerce shop managers have manage_woocommerce but NOT manage_options,
	 * so the sync tool is intentionally restricted to site administrators only.
	 *
	 * @return void
	 */
	public function add_menu_page(): void {
		add_submenu_page(
			'woocommerce',                               // Parent slug.
			__( 'Multi Stock Sync', 'woo-multi-stock' ), // Browser tab / page title.
			__( 'Multi Stock', 'woo-multi-stock' ),      // Menu label (shorter).
			'manage_options',                            // Required capability.
			self::PAGE_SLUG,                             // Menu slug (must be unique).
			array( $this, 'render_page' )                // Callback for page HTML.
		);
	}

	/**
	 * Register plugin settings with the WordPress Settings API.
	 *
	 * WHY the Settings API?
	 * It handles nonce generation, sanitisation callbacks, and the options.php
	 * form action automatically — far less boilerplate and more secure than a
	 * hand-rolled form with manual $_POST parsing.
	 *
	 * @return void
	 */
	public function register_settings(): void {

		// ── Option: Warehouse Name ────────────────────────────────────────────
		register_setting(
			self::SETTINGS_GROUP,        // Group (matches settings_fields() call).
			self::OPT_WAREHOUSE,         // Option name in wp_options.
			array(
				'type'              => 'string',
				'description'       => __( 'Display name for this warehouse / magazzino.', 'woo-multi-stock' ),
				'sanitize_callback' => 'sanitize_text_field', // Strips tags, extra whitespace.
				'default'           => '',
			)
		);

		// ── Option: CSV Remote URL ────────────────────────────────────────────
		register_setting(
			self::SETTINGS_GROUP,
			self::OPT_CSV_URL,
			array(
				'type'              => 'string',
				'description'       => __( 'Full URL of the remote CSV file (must be publicly accessible or use HTTP Basic Auth).', 'woo-multi-stock' ),
				'sanitize_callback' => 'esc_url_raw', // Normalises URL, strips disallowed protocols.
				'default'           => '',
			)
		);

		// ── Settings section ──────────────────────────────────────────────────
		// A single section groups both fields visually on the page.
		add_settings_section(
			self::SECTION_ID,
			__( 'Warehouse Configuration', 'woo-multi-stock' ),
			array( $this, 'render_section_description' ), // Optional description below section title.
			self::PAGE_SLUG
		);

		// ── Field: Warehouse Name ─────────────────────────────────────────────
		add_settings_field(
			self::OPT_WAREHOUSE,
			__( 'Warehouse Name', 'woo-multi-stock' ),
			array( $this, 'render_field_warehouse' ),
			self::PAGE_SLUG,
			self::SECTION_ID,
			array( 'label_for' => self::OPT_WAREHOUSE ) // Wraps label in <label for="...">
		);

		// ── Field: CSV Remote URL ─────────────────────────────────────────────
		add_settings_field(
			self::OPT_CSV_URL,
			__( 'CSV Remote URL', 'woo-multi-stock' ),
			array( $this, 'render_field_csv_url' ),
			self::PAGE_SLUG,
			self::SECTION_ID,
			array( 'label_for' => self::OPT_CSV_URL )
		);
	}

	/**
	 * Enqueue the plugin's admin script and pass PHP data to JavaScript.
	 *
	 * The $hook_suffix parameter allows us to load our assets ONLY on the
	 * plugin's own page, not on every WP admin screen. This keeps the admin
	 * lean and avoids accidental JS conflicts on other pages.
	 *
	 * wp_localize_script() serialises the $data array as a JavaScript object
	 * named `wmsData`, available globally in admin-script.js.
	 *
	 * @param string $hook_suffix  Current admin page hook suffix from WordPress.
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		// The hook suffix for an add_submenu_page() page is built as:
		// "{parent_slug}_page_{menu_slug}". For our case:
		// "woocommerce_page_woo-multi-stock"
		// We check with strpos() for PHP 7.4 compatibility.
		if ( false === strpos( $hook_suffix, self::PAGE_SLUG ) ) {
			return;
		}

		wp_enqueue_script(
			'woo-multi-stock-admin',                            // Handle.
			WMS_PLUGIN_URL . 'assets/admin-script.js',         // Source URL.
			array( 'jquery' ),                                  // Dependency.
			WMS_VERSION,                                        // Version (cache-busting).
			true                                                // Load in footer (best practice).
		);

		// Pass PHP-side data to JavaScript.
		// All strings are translatable so the UI works in any language.
		wp_localize_script(
			'woo-multi-stock-admin',
			'wmsData', // Global JS object name used in admin-script.js.
			array(
				// WordPress AJAX endpoint.
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),

				// Nonce for both AJAX actions. A single nonce covers both
				// woo_multi_stock_download and woo_multi_stock_process_batch
				// because they share the same action string.
				'nonce'     => wp_create_nonce( self::NONCE_ACTION ),

				// Rows processed per AJAX request. Keeping this in sync with
				// BATCH_SIZE in Class-Processor.php (both default to 50).
				// Defined here so JS can display accurate progress steps.
				'batchSize' => 50,

				// Translatable UI strings. Using a nested i18n object keeps
				// wmsData clean and mirrors the convention used by Gutenberg.
				'i18n'      => array(
					'startSync'     => __( 'Start Sync', 'woo-multi-stock' ),
					'syncing'       => __( 'Syncing…', 'woo-multi-stock' ),
					'done'          => __( 'Sync complete.', 'woo-multi-stock' ),
					'errorDownload' => __( 'Failed to download CSV. Check the URL in settings.', 'woo-multi-stock' ),
					'errorBatch'    => __( 'An error occurred during batch processing. Please retry.', 'woo-multi-stock' ),
					/* translators: 1: processed count  2: not-found count  3: updated count */
					'summaryTpl'    => __( 'Processed %1$d products, %2$d SKUs not found, %3$d SKUs updated.', 'woo-multi-stock' ),
				),
			)
		);
	}

	// ── Rendering methods ─────────────────────────────────────────────────────

	/**
	 * Render the full admin page.
	 *
	 * The page is split into two logical sections:
	 *
	 * A) Settings form — saved via options.php (Settings API standard flow).
	 *    Only administrators with manage_options capability reach this point
	 *    (enforced by add_submenu_page's capability check), but we add an
	 *    explicit current_user_can() check here as a defence-in-depth measure.
	 *
	 * B) Sync UI — the "Start Sync" button, progress bar, and live counters.
	 *    These are not a form; they're driven entirely by admin-script.js.
	 *
	 * @return void
	 */
	public function render_page(): void {
		// Capability check: belt-and-suspenders. WordPress already enforces
		// the capability declared in add_submenu_page(), but an extra check
		// here protects against misconfigured role plugins.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You do not have permission to access this page.', 'woo-multi-stock' ),
				403
			);
		}
		?>
		<div class="wrap">

			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<?php
			// Show a settings-updated notice when WordPress redirects back
			// after saving. WordPress adds ?settings-updated=true to the URL.
			if ( isset( $_GET['settings-updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				add_settings_error(
					'woo_multi_stock_messages',
					'woo_multi_stock_message',
					__( 'Settings saved successfully.', 'woo-multi-stock' ),
					'updated'
				);
			}
			settings_errors( 'woo_multi_stock_messages' );
			?>

			<?php /* ── Section A: Settings form ─────────────────────────────── */ ?>
			<form method="post" action="options.php">
				<?php
				// Output hidden fields: _wpnonce, _wp_http_referer, option_page.
				// This is what makes the Settings API handle saving automatically.
				settings_fields( self::SETTINGS_GROUP );

				// Output the section title, description, and all registered fields.
				do_settings_sections( self::PAGE_SLUG );

				// Standard "Save Settings" button with WordPress default styling.
				submit_button( __( 'Save Settings', 'woo-multi-stock' ) );
				?>
			</form>

			<hr>

			<?php /* ── Section B: Sync trigger UI ──────────────────────────── */ ?>
			<h2><?php esc_html_e( 'Stock Synchronisation', 'woo-multi-stock' ); ?></h2>

			<p class="description">
				<?php esc_html_e( 'Click the button below to download the CSV and update the _stock_CMT meta field for all matching products. The native WooCommerce stock is NOT modified.', 'woo-multi-stock' ); ?>
			</p>

			<?php
			// Warn the user if no CSV URL has been saved yet, so they know
			// why the sync would fail before they even click the button.
			$csv_url = get_option( self::OPT_CSV_URL, '' );
			if ( empty( $csv_url ) ) {
				echo '<div class="notice notice-warning inline"><p>';
				esc_html_e( 'No CSV URL is configured. Please fill in the "CSV Remote URL" field above and save settings before syncing.', 'woo-multi-stock' );
				echo '</p></div>';
			}
			?>

			<p>
				<button
					id="wms-start-sync"
					class="button button-primary"
					<?php disabled( empty( $csv_url ) ); ?>
				>
					<?php esc_html_e( 'Start Sync', 'woo-multi-stock' ); ?>
				</button>
			</p>

			<?php /* Progress bar — hidden until sync starts (toggled by JS) */ ?>
			<div id="wms-progress-wrap" style="display:none; margin-top:12px;">
				<progress
					id="wms-progress-bar"
					value="0"
					max="100"
					style="width:100%; max-width:600px; height:20px;"
				></progress>
				<span id="wms-progress-text" style="margin-left:8px;">0%</span>
			</div>

			<?php /* Live counters — hidden until sync starts (toggled by JS) */ ?>
			<div id="wms-counters" style="display:none; margin-top:8px; color:#555;">
				<?php esc_html_e( 'Processed:', 'woo-multi-stock' ); ?>
				<strong id="wms-count-processed">0</strong>
				&nbsp;|&nbsp;
				<?php esc_html_e( 'Not found:', 'woo-multi-stock' ); ?>
				<strong id="wms-count-not-found">0</strong>
				&nbsp;|&nbsp;
				<?php esc_html_e( 'Updated:', 'woo-multi-stock' ); ?>
				<strong id="wms-count-updated">0</strong>
			</div>

			<?php /* Status / summary message area — populated by JS */ ?>
			<div id="wms-status-message" style="margin-top:12px;"></div>

		</div><!-- .wrap -->
		<?php
	}

	/**
	 * Render a short description below the section title.
	 *
	 * The $args array is passed by add_settings_section() and contains
	 * 'id', 'title', and 'callback'. We don't need them here, but the
	 * parameter is required by the callback signature.
	 *
	 * @param array $args  Section arguments from add_settings_section().
	 * @return void
	 */
	public function render_section_description( array $args ): void {
		echo '<p>' . esc_html__( 'Enter the warehouse name and the URL of the remote CSV file containing stock data.', 'woo-multi-stock' ) . '</p>';
	}

	/**
	 * Render the "Warehouse Name" input field.
	 *
	 * The $args['label_for'] value is already used by the Settings API to
	 * wrap the field label in <label for="...">. We use the same value as
	 * the input's id attribute so clicking the label focuses the field.
	 *
	 * @param array $args  Field arguments from add_settings_field().
	 * @return void
	 */
	public function render_field_warehouse( array $args ): void {
		$value = get_option( self::OPT_WAREHOUSE, '' );
		?>
		<input
			type="text"
			id="<?php echo esc_attr( self::OPT_WAREHOUSE ); ?>"
			name="<?php echo esc_attr( self::OPT_WAREHOUSE ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
			class="regular-text"
			placeholder="<?php esc_attr_e( 'e.g. Main Warehouse', 'woo-multi-stock' ); ?>"
		>
		<p class="description">
			<?php esc_html_e( 'A label for this warehouse — used for display purposes only.', 'woo-multi-stock' ); ?>
		</p>
		<?php
	}

	/**
	 * Render the "CSV Remote URL" input field.
	 *
	 * Using type="url" triggers native browser URL validation (basic format
	 * check before the form is even submitted). Server-side sanitisation via
	 * esc_url_raw() is still applied on save — browser validation is not a
	 * security measure, just a UX convenience.
	 *
	 * @param array $args  Field arguments from add_settings_field().
	 * @return void
	 */
	public function render_field_csv_url( array $args ): void {
		$value = get_option( self::OPT_CSV_URL, '' );
		?>
		<input
			type="url"
			id="<?php echo esc_attr( self::OPT_CSV_URL ); ?>"
			name="<?php echo esc_attr( self::OPT_CSV_URL ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
			class="regular-text"
			placeholder="https://example.com/stock.csv"
		>
		<p class="description">
			<?php esc_html_e( 'Full URL of the remote CSV file. Must be accessible from the server (not just your local machine). Delimiter: semicolon (;).', 'woo-multi-stock' ); ?>
		</p>
		<?php
	}
}
