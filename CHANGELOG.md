# Changelog

All notable changes to **Woo Multi Stock** will be documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
This project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [Unreleased]

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

[Unreleased]: https://github.com/your-org/woo-multi-stock/compare/v1.0.1...HEAD
[1.0.1]: https://github.com/your-org/woo-multi-stock/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/your-org/woo-multi-stock/releases/tag/v1.0.0
