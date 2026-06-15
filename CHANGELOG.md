# Changelog

All notable changes to **Woo Multi Stock** will be documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
This project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [Unreleased]

---

## [1.7.1] — 2026-06-15

### Fixed
- **Cartelle versionate all'installazione manuale**: `Updater::fix_source_dir()` ora gestisce anche l'upload manuale dello zip della release (oltre all'update automatico). In quel contesto WordPress non fornisce il basename del plugin, quindi il pacchetto viene riconosciuto dalla presenza del file principale `woo-multi-stock.php` nella cartella estratta e rinominato in uno slug stabile (`woo-multi-stock`). Evita la creazione di cartelle versionate diverse (`woo-multi-stock-1.7.0`, `-1.7.1`, …) trattate come plugin distinti. L'update automatico continua a reinstallare in-place nella cartella esistente.

---

## [1.7.0] — 2026-06-15

### Added
- **Aggiornamento automatico via GitHub Releases** (`includes/Class-Updater.php`): nuova classe `Updater` che integra il plugin con il sistema di update nativo di WordPress leggendo direttamente l'API delle release del repository pubblico `mavidasnc/woo-multi-stock`, **senza librerie esterne**.
  - Interroga `/releases/latest` con caching in transient (`wms_github_release`, TTL 6 ore) e User-Agent obbligatorio; in caso di errore di rete o risposta non valida non genera avvisi né update fantasma.
  - Inietta i dati di update nel transient `update_plugins` (hook `pre_set_site_transient_update_plugins`) solo se la release remota è maggiore di `WMS_VERSION`.
  - Popup "Visualizza dettagli" via `plugins_api` con changelog dal corpo della release.
  - `upgrader_source_selection` rinomina la cartella estratta dallo zipball (`owner-repo-<sha>`) nella cartella reale del plugin, così l'update reinstalla in-place senza duplicati.
  - La cache si svuota dopo ogni update (`upgrader_process_complete`) e a ogni controllo manuale.
- **Tab "Aggiornamenti"** nella pagina admin: mostra versione installata e ultima versione, link al repository, bottone **"Controlla aggiornamenti"** (AJAX `wms_check_update`) e avviso con changelog + link "Aggiorna ora" (flusso di update nativo) quando è disponibile una nuova versione.
- **Pagina admin a 3 tab** (navigazione lato client, senza reload): Tab 1 _Configurazione & Sync_ (gestione magazzini + sincronizzazione), Tab 2 _Panoramica Stock_, Tab 3 _Aggiornamenti_.
- **Link nella lista plugin di WordPress**: scorciatoia **Impostazioni** (azioni plugin) e link al repository **GitHub** (meta-link della riga).

### Changed
- Header `Plugin URI` allineato al repository reale (`https://github.com/mavidasnc/woo-multi-stock`), prima placeholder `your-org`.

---

## [1.6.0] — 2026-06-15

### Added
- **Soppressione notifiche email di stock all'admin** (`includes/Class-Backorder-Manager.php`): con il forcing dei backorder attivo, ogni prodotto resta sempre acquistabile anche a stock 0, quindi le notifiche native di WooCommerce inviate all'admin (prodotto in arretrato / esaurito / scorte basse) sono solo rumore. Il nuovo callback `filter_email_actions()` rimuove le action `woocommerce_product_on_backorder`, `woocommerce_no_stock` e `woocommerce_low_stock` dalla lista `woocommerce_email_actions`, impedendo a WooCommerce di registrare quelle email transazionali.
  - La soppressione è **legata al toggle**: il filtro viene registrato solo dentro `register_hooks()`, che esce subito quando il forcing è disattivato → spegnendo il toggle le email native tornano automaticamente (comportamento reversibile, zero overhead a feature spenta).
  - Rispetta l'escape-hatch globale `wms_force_backorders` riusando `should_force( null )`.
  - **Non tocca** la logica di vendita né le altre email d'ordine (nuovo ordine, processing, ecc.): quelle continuano ad arrivare normalmente.

---

## [1.5.1] — 2026-06-05

### Fixed
- **Toggle "Force backorders" non salvato**: il campo `force_backorders` veniva inviato dal JS come stringa `'true'`/`'false'` e confrontato in PHP con `sanitize_key()` + `=== 'true'` — approccio fragile. Sostituito con `'1'`/`'0'` (pattern standard WordPress): JS invia `? '1' : '0'`, PHP confronta con `=== '1'`.
- **Posizione checkbox admin**: il toggle "Force backorders on all products" spostato sopra i bottoni "Aggiungi magazzino" / "Salva configurazione", così è visibile e incluso nel salvataggio prima di cliccare il pulsante.

---

## [1.5.0] — 2026-06-05

### Added
- **`Backorder_Manager` class** (`includes/Class-Backorder-Manager.php`): forza backorder, gestione stock e disponibilità su tutti i prodotti WooCommerce tramite filtri a runtime, **senza scrivere nel database**. Sopravvive a qualsiasi aggiornamento prodotti effettuato da strumenti esterni.
  - Filtri su `woocommerce_product_get_backorders`, `woocommerce_product_variation_get_backorders` → `'yes'`.
  - Filtri su `woocommerce_product_get_manage_stock`, `woocommerce_product_variation_get_manage_stock` → `true`.
  - Filtri su `woocommerce_product_get_stock_status`, `woocommerce_product_variation_get_stock_status` → `'onbackorder'` (rende acquistabili anche i prodotti marcati `outofstock` dallo strumento esterno).
  - Safety-net diretti su `woocommerce_product_is_in_stock` e `woocommerce_product_backorders_allowed`.
  - Tutti i filtri a priorità 99; zero hook registrati quando la funzione è disattivata.
- **Toggle admin** nella sezione "Configurazione Magazzini" ("Force backorders on all products"): checkbox che persiste in `woo_multi_stock_force_backorders` (`'yes'`/`'no'`), salvato insieme alla lista magazzini via `wms_save_warehouses`. Default: disattivato.
- **`Warehouse_Manager::force_backorders()` (static)**: getter dell'opzione, usato sia da `Backorder_Manager` che dalla localizzazione JS.
- **Filtro sviluppatore `wms_force_backorders`** (`apply_filters( 'wms_force_backorders', true, $product )`): permette di escludere singoli prodotti dal forzamento tramite codice esterno, senza modificare il plugin.
- Traduzione IT aggiornata con 2 nuove stringhe; `.pot`, `.po`, `.mo` rigenerati (64 stringhe totali).

---

## [1.4.1] — 2026-04-22

### Changed
- **`Total_Updater::collect_ids()` — filtro lingua italiana con WPML**: quando WPML è attivo, la query di raccolta ID aggiunge un `INNER JOIN` su `wp_icl_translations` filtrato per `language_code = 'it'`. Sync All elabora così solo i prodotti italiani (sorgente dello stock), evitando di riscrivere le stesse quantità sulle copie tradotte e dimezzando il numero di ID da processare.

---

## [1.4.0] — 2026-04-22

### Fixed
- **WPML/WCML — sincronizzazione multilingue**: in install con WPML + WooCommerce Multilingual ogni traduzione di un prodotto è un post separato che condivide lo stesso `_sku`. La precedente `Stock_Updater::update()` usava `wc_get_product_id_by_sku()`, filtrato da WCML per la sola lingua admin corrente, e le traduzioni restavano con lo stock non aggiornato. `Stock_Updater` ora esegue una query diretta su `postmeta` (`find_product_ids_by_sku()`) e scrive il meta `_stock_{LABEL}` su **tutti** i post che condividono lo SKU, indipendentemente dalla lingua. Il hook `woo_multi_stock_after_row_update` si innesca una volta per ciascun post aggiornato. Funziona identicamente quando WPML non è installato (restituisce un singolo ID).

### Added
- **Colonna ID** come prima colonna della tabella Stock Overview: mostra il `post_id` di prodotto o variazione per riferimento rapido.
- **Colonna Lingua** nella tabella Stock Overview, visibile solo se WPML è rilevato: mostra il codice lingua (`IT`, `EN`, …) derivato da `wp_icl_translations.language_code` via LEFT JOIN nella query di pagina. Il flag `wpml_active` è restituito dall'AJAX `wms_stock_table_fetch` e il frontend mostra/nasconde la colonna di conseguenza.
- `Stock_Table::is_wpml_available()` — helper statico cached che verifica l'esistenza di `{prefix}icl_translations` (controlla la tabella reale, non la classe/costante WPML, così resta corretto anche se WPML è temporaneamente disattivato).

### Changed
- **Tabella Stock Overview — prodotti padre variabili nascosti**: la query aggiunge un `NOT EXISTS` sulla relazione `post_parent` → `product_variation` in stato non-cestino. I parent variabili non compaiono più fra le righe visibili perché sono ridondanti (le variazioni figlie portano lo stock reale). I prodotti semplici passano indenni.
- Count delle colonne base aumentato da 5 a 7 (ID + Lang) in `rebuildThead()` e nel `colspan` dello stato di caricamento.

### Notes
- La somma dei magazzini e il calcolo `Total_Updater` restano per-post: ogni traduzione viene elaborata individualmente e ottiene lo stesso valore di `_stock` perché le sue meta `_stock_*` sono state scritte identiche dallo `Stock_Updater`. Non c'è doppio conteggio.

---

## [1.3.0] — 2026-04-22

### Added
- **Colonna "Parent SKU"** nella tabella Stock Overview: per le variazioni (`product_variation`) viene mostrato lo SKU del prodotto padre come prima colonna. Per prodotti semplici la cella rimane vuota.
- **Ricerca per SKU padre**: il filtro SKU nella tabella matcha ora sia lo SKU della riga sia lo SKU del prodotto padre, consentendo di trovare tutte le variazioni inserendo lo SKU del prodotto variabile.
- **Pulsante "Sync" per riga**: ogni riga della tabella Stock Overview ha un nuovo pulsante che ricalcola lo stock WC (`_stock`) per il singolo prodotto sommando i meta `_stock_*` esistenti — senza scaricare alcun CSV. La cella WC Stock si aggiorna in-place al completamento. Nuovo AJAX action `wms_total_single` in `Total_Updater`.
- **Contatori live per Sync All**: il blocco "Sync All → WC Stock" mostra ora tre contatori aggiornati in tempo reale — **Elaborati / Aggiornati / Skippati** — analoghi a quelli già presenti per la sincronizzazione per-magazzino.
- **Skip ottimizzato in `Total_Updater::process_ids()`**: se la somma dei meta `_stock_*` è uguale al valore `_stock` corrente e il flag `manage_stock` non richiede aggiornamento, il prodotto viene saltato senza alcuna scrittura su DB. Questo evita chiamate superflue a `wc_update_product_stock()` (che triggera `wc_delete_product_transients()` ad ogni invocazione). Il return di `process_ids()` include ora il contatore `skipped` oltre a `processed` e `updated`.
- WP-CLI `sync-total`: il messaggio di completamento include ora anche il conteggio dei prodotti skippati (`skipped: N`).

### Changed
- **`Stock_Table`**: rimpiazzato il triplo round-trip (WP_Query + `get_posts()` + postmeta) con due query SQL dirette: (1) una SELECT paginata con LEFT JOIN su `_sku` e parent `_sku` che restituisce ID, titolo, SKU e parent SKU in un colpo solo; (2) la query batched postmeta per `_stock` e `_stock_*`. Riduzione netta delle query da ~3 a 2 per pagina.
- Il filtro per magazzino nella tabella ora usa un INNER JOIN invece di `meta_query`, eliminando un ulteriore passaggio WP_Query.
- Colonna count nella `<tbody>` di caricamento aggiornata da `3 + count(warehouses)` a `5 + count(warehouses)` per le due nuove colonne (Parent SKU + Azioni).

---

## [1.2.0] — 2026-04-14

### Added
- **WP-CLI support** (`includes/Class-CLI.php`): new `CLI` class registers the `wms` command group via `WP_CLI::add_command()`.
  - `wp wms sync [--warehouse=<id|all>]` — downloads the remote CSV and updates `_stock_*` warehouse meta fields for one or all configured warehouses; shows a per-warehouse progress bar and a final summary.
  - `wp wms sync-total` — aggregates all `_stock_*` metas into WooCommerce native `_stock` (same logic as the admin "Sync All" button); shows a global progress bar.
  - Both commands can be scheduled via system cron (see CLAUDE.md → WP-CLI for example crontab entries).
- **`Processor::fetch_rows(array $warehouse): array|\WP_Error`** — public method that encapsulates the entire HTTP download + CSV parse without writing to any transient. Returns a structured rows array or a `WP_Error` with a specific error code (`wms_no_url`, `wms_http_error`, `wms_http_status`, `wms_empty_body`, `wms_html_response`, `wms_no_rows`). Reused by both `handle_download()` and the CLI `sync` command.
- **`Total_Updater::collect_ids(): array`** — public method that queries all product/variation IDs with warehouse metas and returns `{ids, variation_ids}` without touching any transient. Called by `handle_prepare()` and the CLI `sync-total` command.
- **`Total_Updater::process_ids(array $ids, array $variation_set): array`** — public method that processes a list of IDs (sum metas → write WC stock) and returns `{processed, updated}`. Called by `handle_process_batch()` with a batch slice and by the CLI with arbitrary-sized slices.

### Changed
- `Processor::parse_csv()` visibility changed from `private` to `protected` so `fetch_rows()` can call it without duplication and subclasses can override it if needed.
- `Processor::handle_download()` refactored into a thin AJAX wrapper: security checks → `fetch_rows()` → `set_transient()` → JSON. No logic duplication with the CLI path.
- `Total_Updater::handle_prepare()` refactored into a thin AJAX wrapper: security checks → `collect_ids()` → `set_transient()` × 2 → JSON.
- `Total_Updater::handle_process_batch()` refactored into a thin AJAX wrapper: reads transients → `process_ids($batch, $variation_set)` → JSON.
- `Total_Updater` "Sync All" now sets `_manage_stock = 'yes'` on product **variations** that do not yet have stock management enabled, so WooCommerce properly recalculates `_stock_status`. Simple products and variable-product parents are left unchanged. The check is skipped when the flag is already `'yes'` to avoid unnecessary DB writes.
- WP-CLI command registration added to `plugins_loaded` (priority 11) **outside** the `is_admin()` guard, because `is_admin()` returns `false` in WP-CLI context.

---

## [1.1.0] — 2026-03-20

### Added
- **Multi-warehouse support**: the plugin now manages N warehouses, each identified by a stable `id` slug (derived once from the label via `sanitize_title()`), a mutable `label`, and a `csv_url`. Configuration is stored in the new `woo_multi_stock_warehouses` option.
- **`Warehouse_Manager` class** (`includes/Class-Warehouse-Manager.php`): single source of truth for warehouse CRUD, meta-key derivation (`_stock_{LABEL}`), transient-key derivation (`wms_csv_{id}`), and AJAX save handler (`wms_save_warehouses`).
- **Per-warehouse meta fields**: each warehouse writes to its own `_stock_{LABEL}` meta key. The existing `_stock_CMT` data is fully preserved — backward-compatible at both the PHP API and the database level.
- **`Total_Updater` class** (`includes/Class-Total-Updater.php`): two-phase AJAX batch (`wms_total_prepare` + `wms_total_batch`) that sums all `_stock_[A-Za-z0-9]+` meta values per product/variation and writes the total to WooCommerce native `_stock` (using `wc_update_product_stock()` when `manage_stock = yes`).
- **`Stock_Table` class** (`includes/Class-Stock-Table.php`): server-side paginated AJAX table (`wms_stock_table_fetch`, 50 rows/page) with SKU search. Uses `WP_Query` with `fields=ids` + a single batched SQL meta query (2 DB queries per page) — designed for catalogues with 5000+ variations.
- **Admin UI — 3-section redesign**:
  - **Section A** — Warehouse management table: add / edit / remove warehouses, live meta-key preview, save via AJAX.
  - **Section B** — Per-warehouse Sync buttons (each with progress bar + counters) and a global "Sync All → WC Stock" button.
  - **Section C** — Stock overview table: SKU | Product/Variation | WC Stock | one column per warehouse; paginated, filterable by SKU.
- **Lazy migration** from v1.0.x single-warehouse options: on first load after upgrade, `Warehouse_Manager::maybe_migrate()` auto-creates the `woo_multi_stock_warehouses` option from `woo_multi_stock_warehouse_name` + `woo_multi_stock_csv_url`. Legacy options are **not** deleted.
- Italian translation updated with all new strings (61 total); `.pot`, `.po`, `.mo` regenerated.

### Changed
- `Stock_Updater`: added optional `$meta_key` constructor parameter (default = `'_stock_CMT'`). All existing `new Stock_Updater()` call sites remain 100% compatible.
- `Processor`: both AJAX handlers now resolve the warehouse from a `warehouse_id` POST parameter via `Warehouse_Manager`; the transient key and meta key are dynamic per-warehouse. Removed the hardcoded `TRANSIENT_KEY` constant.
- `wmsData` JS object extended with `warehouses` array and new `i18n` keys (`syncAll`, `calculating`, `calcDone`, `saveWarehouses`, `addWarehouse`, `searchSku`, `loading`, `noResults`, `pageInfo`, …).

---

## [1.0.1] — 2026-02-27

### Fixed
- **UTF-8 BOM stripping** in `parse_csv()`: CSV files exported from Excel or Windows systems begin with the 3-byte BOM sequence (`\xEF\xBB\xBF`). Without this fix, the BOM would attach to the first data-row SKU if the header were absent, causing a silent SKU lookup failure. The fix uses `substr()` comparison to avoid stripping legitimate leading bytes.
- **HTML response guard** in `handle_download()`: when a URL returns an HTML page instead of CSV data (e.g. a Google Drive *view/share* link rather than a direct download URL), the plugin now detects the `<!DOCTYPE` / `<html` signature and returns a descriptive error message instead of silently processing the HTML as CSV data. The error message includes guidance on constructing the correct Google Drive direct-download URL (`drive.google.com/uc?export=download&id=FILE_ID`).

### Added
- Italian translation (`it_IT`): `languages/woo-multi-stock.pot` (template), `woo-multi-stock-it_IT.po` (source), `woo-multi-stock-it_IT.mo` (compiled binary) — 36 strings translated.

---

## [1.0.0] — 2026-02-27

### Added
- Admin settings page under **WooCommerce → Multi Stock** with two fields:
  - **Warehouse Name** — display label for the warehouse (stored in `woo_multi_stock_warehouse_name`).
  - **CSV Remote URL** — URL of the remote semicolon-delimited CSV file (stored in `woo_multi_stock_csv_url`).
- **Start Sync** button that triggers a two-phase AJAX synchronisation:
  1. Downloads the remote CSV via `wp_remote_get()` and caches parsed rows in a WordPress transient (`woo_multi_stock_csv_rows`, TTL 1 hour).
  2. Processes rows in batches of 50, updating the `_stock_CMT` meta field on each matched product or variation.
- Live progress bar and row counters (Processed / Not found / Updated) updated after every batch.
- Final summary notice: *"Processed X products, Y SKUs not found, Z SKUs updated."*
- CSV parsing: semicolon delimiter, European decimal comma quantity conversion (`"1,0000000000000"` → `1`), automatic header-row skip.
- Inline PSR-4-style autoloader (`WooMultiStock\` namespace → `includes/Class-*.php`).
- HPOS (High-Performance Order Storage) compatibility declaration for WooCommerce 7.1+.
- Graceful PHP version guard: admin notice + automatic deactivation if PHP < 7.4.
- Graceful WooCommerce guard: admin notice if WooCommerce is not active.
- Extensibility hooks:
  - `woo_multi_stock_before_processing` *(action)* — fires at the start of every batch.
  - `woo_multi_stock_after_row_update` *(action)* — fires after each successful `_stock_CMT` meta write.
  - `woo_multi_stock_download_timeout` *(filter)* — controls `wp_remote_get()` timeout (default: 60 s).
- `Stock_Updater::META_KEY` public constant (`'_stock_CMT'`) as the single authoritative reference for the meta key name.

---

[Unreleased]: https://github.com/your-org/woo-multi-stock/compare/v1.5.1...HEAD
[1.5.1]: https://github.com/your-org/woo-multi-stock/compare/v1.5.0...v1.5.1
[1.5.0]: https://github.com/your-org/woo-multi-stock/compare/v1.4.1...v1.5.0
[1.4.1]: https://github.com/your-org/woo-multi-stock/compare/v1.4.0...v1.4.1
[1.4.0]: https://github.com/your-org/woo-multi-stock/compare/v1.3.0...v1.4.0
[1.3.0]: https://github.com/your-org/woo-multi-stock/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/your-org/woo-multi-stock/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/your-org/woo-multi-stock/compare/v1.0.1...v1.1.0
[1.0.1]: https://github.com/your-org/woo-multi-stock/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/your-org/woo-multi-stock/releases/tag/v1.0.0
