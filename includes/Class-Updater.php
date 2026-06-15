<?php
/**
 * Class Updater
 *
 * Self-updater che integra il plugin con il sistema di aggiornamento nativo di
 * WordPress leggendo direttamente le GitHub Releases del repository pubblico
 * (nessuna libreria esterna tipo plugin-update-checker).
 *
 * Flusso:
 *  1. Interroga l'endpoint /releases/latest dell'API GitHub (con caching in
 *     transient) per scoprire l'ultima versione pubblicata.
 *  2. Se la versione remota è maggiore di WMS_VERSION, inietta i dati di update
 *     nel transient `update_plugins` letto da WordPress.
 *  3. Fornisce il popup "Visualizza dettagli" via il filtro `plugins_api`.
 *  4. Lo zipball auto-generato da GitHub si estrae in una cartella con nome
 *     `owner-repo-<sha>`: `fix_source_dir()` la rinomina nella cartella reale
 *     del plugin prima dell'installazione.
 *
 * Espone inoltre l'handler AJAX `wms_check_update` usato dalla tab
 * "Aggiornamenti" per forzare il controllo e mostrare lo stato.
 *
 * @package WooMultiStock
 */

namespace WooMultiStock;

// Prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GitHub-based plugin updater.
 */
class Updater {

	// ── Configurazione repository ─────────────────────────────────────────────

	/** @var string Proprietario del repository GitHub. */
	private const GITHUB_OWNER = 'mavidasnc';

	/** @var string Nome del repository GitHub. */
	private const GITHUB_REPO = 'woo-multi-stock';

	/** @var string Chiave del transient che mette in cache la release remota. */
	private const CACHE_KEY = 'wms_github_release';

	/** @var int Durata della cache in secondi (6 ore). */
	private const CACHE_TTL = 6 * HOUR_IN_SECONDS;

	// ── Public API ────────────────────────────────────────────────────────────

	/**
	 * Registra tutti gli hook necessari all'aggiornamento.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'fix_source_dir' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( $this, 'clear_cache_after_update' ), 10, 2 );

		// Controllo manuale dalla tab "Aggiornamenti".
		add_action( 'wp_ajax_wms_check_update', array( $this, 'handle_check_update' ) );
	}

	// ── Lettura release remota ─────────────────────────────────────────────────

	/**
	 * Recupera l'ultima release pubblicata su GitHub, con caching in transient.
	 *
	 * @param bool $force Se true ignora la cache e ricontatta l'API.
	 * @return array|false  Dati normalizzati della release, o false su errore.
	 */
	public function get_remote_release( bool $force = false ) {
		// Cache hit: restituisci subito (salvo controllo forzato).
		if ( ! $force ) {
			$cached = get_transient( self::CACHE_KEY );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		$url = sprintf(
			'https://api.github.com/repos/%s/%s/releases/latest',
			self::GITHUB_OWNER,
			self::GITHUB_REPO
		);

		// User-Agent è obbligatorio per l'API GitHub; Accept fissa la versione API.
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'WooMultiStock-Updater',
				),
			)
		);

		// Errore di rete o risposta non valida → nessun update mostrato.
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $data ) || empty( $data['tag_name'] ) || empty( $data['zipball_url'] ) ) {
			return false;
		}

		// Normalizza i dati utili: il tag può avere il prefisso "v" → rimosso.
		$release = array(
			'version'      => ltrim( (string) $data['tag_name'], 'vV' ),
			'download_url' => (string) $data['zipball_url'],
			'changelog'    => isset( $data['body'] ) ? (string) $data['body'] : '',
			'html_url'     => isset( $data['html_url'] ) ? (string) $data['html_url'] : '',
			'published_at' => isset( $data['published_at'] ) ? (string) $data['published_at'] : '',
		);

		set_transient( self::CACHE_KEY, $release, self::CACHE_TTL );

		return $release;
	}

	// ── Hook callbacks ──────────────────────────────────────────────────────────

	/**
	 * Inietta i dati di update nel transient letto da WordPress.
	 *
	 * @param mixed $transient L'oggetto transient update_plugins (può essere vuoto).
	 * @return mixed  Il transient, eventualmente arricchito con la nostra response.
	 */
	public function inject_update( $transient ) {
		// Su alcune chiamate iniziali $transient non è ancora un oggetto.
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$release = $this->get_remote_release();

		if ( false === $release ) {
			return $transient;
		}

		// Aggiorna solo se la versione remota è maggiore di quella installata.
		if ( ! version_compare( $release['version'], WMS_VERSION, '>' ) ) {
			return $transient;
		}

		$basename = $this->get_basename();

		$update                 = new \stdClass();
		$update->slug           = $this->get_slug();
		$update->plugin         = $basename;
		$update->new_version    = $release['version'];
		$update->package        = $release['download_url'];
		$update->url            = $release['html_url'];
		$update->tested         = '';
		$update->requires_php   = '7.4';

		$transient->response[ $basename ] = $update;

		return $transient;
	}

	/**
	 * Fornisce i dati del popup "Visualizza dettagli" del plugin.
	 *
	 * @param mixed  $result Valore di default (false) o oggetto già fornito.
	 * @param string $action Azione richiesta dall'API plugin.
	 * @param object $args   Argomenti della richiesta (contiene `slug`).
	 * @return mixed  Oggetto info plugin, o il valore originale se non è il nostro.
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		if ( empty( $args->slug ) || $this->get_slug() !== $args->slug ) {
			return $result;
		}

		$release = $this->get_remote_release();

		if ( false === $release ) {
			return $result;
		}

		$info               = new \stdClass();
		$info->name         = 'Woo Multi Stock';
		$info->slug         = $this->get_slug();
		$info->version      = $release['version'];
		$info->author       = '<a href="https://mavida.com">Mavida s.n.c.</a>';
		$info->homepage     = $release['html_url'];
		$info->download_link = $release['download_url'];
		$info->sections     = array(
			// Il body della release è Markdown: lo mostriamo come testo sicuro.
			'changelog' => wpautop( wp_kses_post( $release['changelog'] ) ),
		);

		return $info;
	}

	/**
	 * Rinomina la cartella estratta nella cartella corretta del plugin.
	 *
	 * Gli archivi GitHub si estraggono in cartelle dal nome variabile:
	 *  - zipball API (update automatico): `owner-repo-<sha>/`;
	 *  - zip "Source code" della release (install manuale): `repo-<versione>/`.
	 * In entrambi i casi WordPress, senza rinomina, creerebbe una cartella nuova
	 * (e diversa a ogni versione) invece di reinstallare in-place.
	 *
	 * Due scenari gestiti:
	 *  1. Update automatico — `$hook_extra['plugin']` contiene il nostro basename:
	 *     rinominiamo nella cartella reale già installata (es. `wp-multi-magazzino`).
	 *  2. Install manuale via upload zip — `$hook_extra['plugin']` è assente:
	 *     riconosciamo il pacchetto dalla presenza del file principale e forziamo
	 *     uno slug stabile (`woo-multi-stock`), così non si creano cartelle
	 *     versionate a ogni upload.
	 *
	 * @param string $source        Path della cartella sorgente estratta.
	 * @param string $remote_source Path della cartella temporanea contenitore.
	 * @param object $upgrader      Istanza WP_Upgrader (non usata).
	 * @param array  $hook_extra    Contesto: contiene `plugin` = basename (solo in update).
	 * @return string|\WP_Error  Nuovo path sorgente, o l'originale se non è il nostro.
	 */
	public function fix_source_dir( $source, $remote_source, $upgrader, $hook_extra ) {
		global $wp_filesystem;

		if ( ! $wp_filesystem ) {
			return $source;
		}

		if ( ! empty( $hook_extra['plugin'] ) ) {
			// Scenario 1 — update di un plugin: interveniamo solo se è il nostro.
			if ( $this->get_basename() !== $hook_extra['plugin'] ) {
				return $source;
			}
			// Cartella reale del plugin già installato (es. "wp-multi-magazzino").
			$desired_slug = dirname( $this->get_basename() );
		} else {
			// Scenario 2 — install manuale: nessun basename nel contesto. Riconosciamo
			// il nostro pacchetto dalla presenza del file principale nella cartella
			// estratta; in caso negativo non è roba nostra e lasciamo invariato.
			$main_file = basename( WMS_PLUGIN_FILE ); // "woo-multi-stock.php"
			if ( ! $wp_filesystem->exists( trailingslashit( $source ) . $main_file ) ) {
				return $source;
			}
			// Slug stabile: evita cartelle versionate a ogni upload manuale.
			$desired_slug = 'woo-multi-stock';
		}

		$desired_path = trailingslashit( $remote_source ) . $desired_slug;

		// Già nel nome corretto: niente da fare.
		if ( untrailingslashit( $source ) === untrailingslashit( $desired_path ) ) {
			return $source;
		}

		// Sposta/rinomina la cartella estratta nel nome atteso.
		if ( $wp_filesystem->move( $source, $desired_path, true ) ) {
			return trailingslashit( $desired_path );
		}

		return new \WP_Error(
			'wms_rename_failed',
			__( 'Unable to rename the update package folder.', 'woo-multi-stock' )
		);
	}

	/**
	 * Svuota la cache della release dopo un aggiornamento di plugin andato a buon fine.
	 *
	 * @param object $upgrader Istanza WP_Upgrader (non usata).
	 * @param array  $data     Dati del processo (`action`, `type`, `plugins`).
	 * @return void
	 */
	public function clear_cache_after_update( $upgrader, $data ): void {
		if ( isset( $data['action'], $data['type'] ) && 'update' === $data['action'] && 'plugin' === $data['type'] ) {
			delete_transient( self::CACHE_KEY );
		}
	}

	// ── AJAX: controllo manuale ────────────────────────────────────────────────

	/**
	 * Handler AJAX `wms_check_update`: forza il controllo e restituisce lo stato.
	 *
	 * @return void
	 */
	public function handle_check_update(): void {
		check_ajax_referer( Admin::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'woo-multi-stock' ) ), 403 );
		}

		$release = $this->force_check();

		$current = WMS_VERSION;

		if ( false === $release ) {
			wp_send_json_error(
				array( 'message' => __( 'Unable to contact GitHub. Please retry later.', 'woo-multi-stock' ) )
			);
		}

		$update_available = version_compare( $release['version'], $current, '>' );

		wp_send_json_success(
			array(
				'current'          => $current,
				'latest'           => $release['version'],
				'update_available' => $update_available,
				'changelog'        => wpautop( wp_kses_post( $release['changelog'] ) ),
				'html_url'         => $release['html_url'],
				'published_at'     => $release['published_at'],
				'upgrade_url'      => $this->get_upgrade_url(),
			)
		);
	}

	/**
	 * Svuota la cache e ricontatta GitHub immediatamente.
	 *
	 * @return array|false  Dati della release, o false su errore.
	 */
	public function force_check() {
		delete_transient( self::CACHE_KEY );

		// Allinea anche il transient nativo di WordPress così l'eventuale avviso
		// nella lista plugin compare senza attendere il cron schedulato.
		delete_site_transient( 'update_plugins' );

		return $this->get_remote_release( true );
	}

	// ── Helpers privati ─────────────────────────────────────────────────────────

	/**
	 * Basename del plugin (es. "wp-multi-magazzino/woo-multi-stock.php").
	 *
	 * @return string
	 */
	private function get_basename(): string {
		return plugin_basename( WMS_PLUGIN_FILE );
	}

	/**
	 * Slug del plugin (cartella, es. "wp-multi-magazzino").
	 *
	 * @return string
	 */
	private function get_slug(): string {
		return dirname( $this->get_basename() );
	}

	/**
	 * URL del flusso di update nativo di WordPress, con nonce.
	 *
	 * @return string
	 */
	private function get_upgrade_url(): string {
		$basename = $this->get_basename();

		return wp_nonce_url(
			self_admin_url( 'update.php?action=upgrade-plugin&plugin=' . rawurlencode( $basename ) ),
			'upgrade-plugin_' . $basename
		);
	}
}
