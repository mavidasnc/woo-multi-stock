# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this plugin does

**Woo Multi Stock** reads a remote semicolon-delimited CSV file and writes warehouse stock quantities to the custom meta field `_stock_CMT` on WooCommerce products and variations. It deliberately does **not** touch native WooCommerce stock (`_stock`, `_stock_status`, `manage_stock`).

## No build step

There is no build process, no npm, no Composer. All files are plain PHP and vanilla JS — edit and deploy directly.

PHP linting (if PHPCS is available locally):
```bash
phpcs --standard=WordPress includes/ woo-multi-stock.php
phpcbf --standard=WordPress includes/ woo-multi-stock.php
```

## Architecture

### Boot sequence (`woo-multi-stock.php`)

The main file is the only entry point. In order:
1. ABSPATH guard → PHP 7.4 check (deactivates gracefully if too old)
2. Constants: `WMS_VERSION`, `WMS_PLUGIN_DIR`, `WMS_PLUGIN_URL`, `WMS_PLUGIN_FILE`, `WMS_TEXT_DOMAIN`
3. Inline PSR-4 autoloader: `WooMultiStock\Foo` → `includes/Class-Foo.php` (underscores become hyphens)
4. HPOS compatibility declaration (`before_woocommerce_init`)
5. `plugins_loaded` at priority 11 (WooCommerce loads at 10): WC guard → textdomain → `Admin::register_hooks()` + `Processor::register_hooks()` (admin-only, covers both page requests and AJAX)

### Class responsibilities

| Class | File | Responsibility |
|---|---|---|
| `WooMultiStock\Admin` | `includes/Class-Admin.php` | Settings API wiring, submenu page, asset enqueue + `wp_localize_script`, HTML rendering |
| `WooMultiStock\Processor` | `includes/Class-Processor.php` | Two `wp_ajax_` handlers, CSV download via `wp_remote_get`, `parse_csv()`, transient cache |
| `WooMultiStock\Stock_Updater` | `includes/Class-Stock-Updater.php` | `wc_get_product_id_by_sku()` lookup + `update_post_meta(_stock_CMT)` |

### AJAX flow

```
JS downloadCSV()   → wp_ajax_woo_multi_stock_download      → Processor::handle_download()
JS processBatch()  → wp_ajax_woo_multi_stock_process_batch → Processor::handle_process_batch()
```

State between AJAX requests: the parsed rows array is stored in transient `woo_multi_stock_csv_rows` (TTL 1 h). The JS passes `offset` (int) in each `process_batch` POST; PHP uses `array_slice($rows, $offset, 50)`.

### JS (`assets/admin-script.js`)

IIFE + jQuery strict mode. Module-level state vars (`totalRows`, `processed`, `notFound`, `updated`, `currentOffset`, `isSyncing`). The batch loop is tail-recursive async: `processBatch()` calls itself from inside `.done()` until `is_done === true`. `isSyncing = false` stops the loop cleanly (future cancel-button hook point).

Data contract: `wmsData` object localised by `Admin::enqueue_assets()` — contains `ajaxUrl`, `nonce`, `batchSize`, `i18n`.

## Key conventions

### PHP
- **Namespace**: `WooMultiStock` — all classes live here
- **Autoloader mapping**: `WooMultiStock\Stock_Updater` → `includes/Class-Stock-Updater.php` (strip prefix → `_` to `-` → prepend `Class-` → `.php`)
- **PHP 7.4 compat**: no `str_starts_with`, no union types in signatures — use `strncmp` and docblock `@return int|false`
- **Security trinity** on every AJAX handler: `check_ajax_referer(Admin::NONCE_ACTION, 'nonce')` → `current_user_can('manage_options')` → `absint`/`esc_url_raw` on inputs
- **No `wp_ajax_nopriv_`** handlers — sync is admin-only
- Quantities stored as `int` via `(int)(float) str_replace(',', '.', $raw)` (European comma decimal)
- CSV first row is always a header → `array_shift($lines)` before parsing loop
- `parse_csv()` strips UTF-8 BOM (`\xEF\xBB\xBF`) via `substr()` comparison before splitting lines
- `handle_download()` detects HTML responses (Google Drive view links, login walls) and returns a descriptive error before attempting CSV parsing

### i18n / translations
- Text domain: `woo-multi-stock` — loaded from `languages/` on `plugins_loaded`
- Template: `languages/woo-multi-stock.pot`
- Italian: `languages/woo-multi-stock-it_IT.po` + compiled `woo-multi-stock-it_IT.mo`
- To regenerate the `.mo` after editing the `.po`: `msgfmt woo-multi-stock-it_IT.po -o woo-multi-stock-it_IT.mo` (or use the inline PHP script pattern from `_po2mo.php` if `msgfmt` is unavailable)
- All user-visible strings use `__()`, `esc_html__()`, `esc_html_e()`, or `esc_attr_e()` with the `woo-multi-stock` domain

### WordPress options
| Key | Sanitize |
|---|---|
| `woo_multi_stock_warehouse_name` | `sanitize_text_field` |
| `woo_multi_stock_csv_url` | `esc_url_raw` |

### Hooks
| Hook | Type | Where fired |
|---|---|---|
| `woo_multi_stock_before_processing` | `do_action` | `Processor::handle_process_batch()` — top of every batch |
| `woo_multi_stock_after_row_update` | `do_action` | `Stock_Updater::update()` — after each successful meta write |
| `woo_multi_stock_download_timeout` | `apply_filters` | `Processor::handle_download()` — before `wp_remote_get` |

`woo_multi_stock_after_row_update` passes 3 args `($product_id, $sku, $qty)` — consumers must declare `add_action(..., 10, 3)`.

## Adding a new class

1. Create `includes/Class-My-Feature.php`
2. Declare `namespace WooMultiStock;` at the top
3. Reference as `new \WooMultiStock\My_Feature()` — the autoloader resolves it automatically (no registration needed)

## Meta field reference

```php
// Read
$qty = (int) get_post_meta( $product_id, \WooMultiStock\Stock_Updater::META_KEY, true );

// The constant value is '_stock_CMT'
```
