# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this plugin does

**Woo Multi Stock** reads remote semicolon-delimited CSV files and writes warehouse stock quantities to per-warehouse custom meta fields (`_stock_{LABEL}`) on WooCommerce products and variations. A "Sync All" operation sums all warehouse metas and writes the total to the native WooCommerce `_stock` field.

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
5. `plugins_loaded` at priority 11: WC guard → textdomain → `Warehouse_Manager::maybe_migrate()` → `Backorder_Manager` + `Updater` registration (both outside `is_admin()`) → WP-CLI registration (if `WP_CLI` defined) → register hooks for the 5 admin classes (admin-only via `is_admin()`)

### Class responsibilities

| Class | File | Responsibility |
|---|---|---|
| `WooMultiStock\Admin` | `includes/Class-Admin.php` | Submenu page, asset enqueue + `wp_localize_script`, HTML rendering (3 sections) |
| `WooMultiStock\Processor` | `includes/Class-Processor.php` | `wp_ajax_woo_multi_stock_download` + `_process_batch`; public `fetch_rows()` for CLI reuse |
| `WooMultiStock\Stock_Updater` | `includes/Class-Stock-Updater.php` | SKU lookup + `update_post_meta($meta_key)`; constructor takes optional meta key |
| `WooMultiStock\Warehouse_Manager` | `includes/Class-Warehouse-Manager.php` | CRUD for `woo_multi_stock_warehouses` option; lazy migration from legacy options; AJAX save |
| `WooMultiStock\Total_Updater` | `includes/Class-Total-Updater.php` | `wms_total_prepare` + `wms_total_batch`: sums `_stock_*` metas → writes WC `_stock`; public `collect_ids()` + `process_ids()` for CLI reuse |
| `WooMultiStock\Stock_Table` | `includes/Class-Stock-Table.php` | `wms_stock_table_fetch`: server-side paginated table (2 queries/page) |
| `WooMultiStock\Updater` | `includes/Class-Updater.php` | GitHub Releases self-updater: injects into `update_plugins` transient, `plugins_api` details, `upgrader_source_selection` folder rename; `wms_check_update` AJAX for the Updates tab |
| `WooMultiStock\CLI` | `includes/Class-CLI.php` | WP-CLI command group `wms`: `sync [--warehouse=<id\|all>]` and `sync-total` |

### Data model — warehouses

```php
// get_option('woo_multi_stock_warehouses', [])
[
    [ 'id' => 'cmt',  'label' => 'CMT',  'csv_url' => 'https://...' ],
    [ 'id' => 'wh2',  'label' => 'WH2',  'csv_url' => 'https://...' ],
]
```

- `id` = `sanitize_title($label)` at creation — **immutable**, determines the transient key (`wms_csv_{id}`)
- `label` = display name — mutable, determines the meta key (`_stock_{label_stripped}`)
- Meta key rule: `'_stock_' . preg_replace('/[^A-Za-z0-9]/', '', $label)` → "CMT" → `_stock_CMT` (backward-compatible)
- Legacy options `woo_multi_stock_warehouse_name` and `woo_multi_stock_csv_url` are auto-migrated on first load and kept in the DB (not deleted)

### AJAX actions

| Action | Class | POST params | Response |
|---|---|---|---|
| `woo_multi_stock_download` | `Processor` | `nonce, warehouse_id` | `{total:N}` |
| `woo_multi_stock_process_batch` | `Processor` | `nonce, warehouse_id, offset` | `{processed, not_found, updated, next_offset, is_done, total}` |
| `wms_save_warehouses` | `Warehouse_Manager` | `nonce, warehouses` (JSON) | `{warehouses:[...]}` |
| `wms_total_prepare` | `Total_Updater` | `nonce` | `{total:N}` |
| `wms_total_batch` | `Total_Updater` | `nonce, offset` | `{processed, updated, next_offset, is_done, total}` |
| `wms_stock_table_fetch` | `Stock_Table` | `nonce, page, search_sku` | `{rows, total_rows, total_pages, current_page, warehouse_labels}` |
| `wms_check_update` | `Updater` | `nonce` | `{current, latest, update_available, changelog, html_url, published_at, upgrade_url}` |

### JS (`assets/admin-script.js`)

IIFE + jQuery. Six sections:
- **A** Warehouse manager: add/remove rows in `#wms-warehouses-tbody`, live meta-key preview, save via `wms_save_warehouses`.
- **B** Per-warehouse sync: each `.wms-sync-block[data-id]` has its own state object and async batch loop.
- **C** Sync All: `wms_total_prepare` → `wms_total_batch` loop via `#wms-sync-all`.
- **D** Stock table: paginated AJAX table with SKU search (`#wms-search-sku`), prev/next buttons.
- **E** Tab navigation: client-side show/hide of the three admin panels (`.wms-nav-tabs .nav-tab` → `.wms-tab-panel`).
- **F** Updates tab: `#wms-check-update` → `wms_check_update`, renders availability notice with changelog + native update link.

Data contract: `wmsData` object — contains `ajaxUrl`, `nonce`, `batchSize`, `warehouses` (array), `i18n`.

## Key conventions

### PHP
- **Namespace**: `WooMultiStock` — all classes live here
- **Autoloader mapping**: `WooMultiStock\Stock_Updater` → `includes/Class-Stock-Updater.php` (strip prefix → `_` to `-` → prepend `Class-` → `.php`)
- **PHP 7.4 compat**: no `str_starts_with`, no union types in signatures — use `strncmp` and docblock `@return int|false`
- **Security trinity** on every AJAX handler: `check_ajax_referer(Admin::NONCE_ACTION, 'nonce')` → `current_user_can('manage_options')` → `absint`/`esc_url_raw`/`sanitize_title` on inputs
- **No `wp_ajax_nopriv_`** handlers — sync is admin-only
- Quantities stored as `int` via `(int)(float) str_replace(',', '.', $raw)` (European comma decimal)
- CSV first row is always a header → `array_shift($lines)` before parsing loop
- `parse_csv()` strips UTF-8 BOM (`\xEF\xBB\xBF`) via `substr()` comparison before splitting lines
- `handle_download()` detects HTML responses and returns a descriptive error before CSV parsing

### i18n / translations
- Text domain: `woo-multi-stock` — loaded from `languages/` on `plugins_loaded`
- Template: `languages/woo-multi-stock.pot`
- Italian: `languages/woo-multi-stock-it_IT.po` + compiled `woo-multi-stock-it_IT.mo`
- To regenerate `.mo` after editing `.po`: `msgfmt woo-multi-stock-it_IT.po -o woo-multi-stock-it_IT.mo` (or `php _po2mo.php` if `msgfmt` is unavailable)
- All user-visible strings use `__()`, `esc_html__()`, `esc_html_e()`, or `esc_attr_e()` with the `woo-multi-stock` domain

### WordPress options
| Key | Type | Sanitize |
|---|---|---|
| `woo_multi_stock_warehouses` | serialised array | per-field in `Warehouse_Manager::handle_ajax_save()` |
| `woo_multi_stock_warehouse_name` | string | legacy — read-only after migration |
| `woo_multi_stock_csv_url` | string | legacy — read-only after migration |

### Hooks
| Hook | Type | Where fired |
|---|---|---|
| `woo_multi_stock_before_processing` | `do_action` | `Processor::handle_process_batch()` — top of every batch |
| `woo_multi_stock_after_row_update` | `do_action` | `Stock_Updater::update()` — after each successful meta write |
| `woo_multi_stock_download_timeout` | `apply_filters` | `Processor::handle_download()` — before `wp_remote_get` |

`woo_multi_stock_after_row_update` passes 3 args `($product_id, $sku, $qty)` — consumers must declare `add_action(..., 10, 3)`.

## WP-CLI commands

The plugin registers a `wms` command group available when WP-CLI is active.

```bash
# Sync a single warehouse (use the warehouse ID slug)
wp wms sync --warehouse=cmt

# Sync all configured warehouses in sequence
wp wms sync --warehouse=all
wp wms sync               # 'all' is the default

# Aggregate all _stock_* metas into WooCommerce native _stock
wp wms sync-total
```

### Typical cron setup

```cron
# /etc/cron.d/woo-multi-stock  (replace /var/www/html with your WP root)
0  2 * * * www-data /usr/local/bin/wp wms sync --warehouse=all --path=/var/www/html --quiet >> /var/log/wms-sync.log 2>&1
30 2 * * * www-data /usr/local/bin/wp wms sync-total --path=/var/www/html --quiet >> /var/log/wms-sync.log 2>&1
```

### Architecture note — CLI vs AJAX

The CLI commands reuse the same public business-logic methods as the AJAX handlers:

| Operation | Public method | Called by |
|---|---|---|
| Download + parse CSV | `Processor::fetch_rows(array $warehouse)` | `handle_download()` + `CLI::sync()` |
| Collect product IDs | `Total_Updater::collect_ids()` | `handle_prepare()` + `CLI::sync_total()` |
| Write WC stock | `Total_Updater::process_ids(array $ids, array $variation_set)` | `handle_process_batch()` + `CLI::sync_total()` |

AJAX handlers add transient-based state so the JS batch loop can span multiple HTTP requests. CLI commands call the same methods directly in a single PHP execution (no transients needed).

## Adding a new class

1. Create `includes/Class-My-Feature.php`
2. Declare `namespace WooMultiStock;` at the top
3. Reference as `new \WooMultiStock\My_Feature()` — the autoloader resolves it automatically (no registration needed)

## Meta field reference

```php
// Read a specific warehouse meta
$qty = (int) get_post_meta( $product_id, '_stock_CMT', true );

// Derive the meta key from a warehouse array
$meta_key = \WooMultiStock\Warehouse_Manager::get_meta_key( $warehouse ); // e.g. '_stock_CMT'

// The backward-compatible constant (still valid)
$qty = (int) get_post_meta( $product_id, \WooMultiStock\Stock_Updater::META_KEY, true );
// Stock_Updater::META_KEY = '_stock_CMT'
```
