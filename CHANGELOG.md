# Changelog

All notable changes to **Woo Multi Stock** will be documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
This project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [Unreleased]

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

[Unreleased]: https://github.com/your-org/woo-multi-stock/compare/v1.2.0...HEAD
[1.2.0]: https://github.com/your-org/woo-multi-stock/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/your-org/woo-multi-stock/compare/v1.0.1...v1.1.0
[1.0.1]: https://github.com/your-org/woo-multi-stock/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/your-org/woo-multi-stock/releases/tag/v1.0.0
