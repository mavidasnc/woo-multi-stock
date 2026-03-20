# Woo Multi Stock

A WordPress plugin that synchronises warehouse stock quantities from a remote
semicolon-delimited CSV file to the custom meta field `_stock_CMT` on
WooCommerce products and variations.

> **Important:** This plugin updates a **custom meta field** (`_stock_CMT`) and
> does **not** modify the native WooCommerce stock (`_stock`, `_stock_status`,
> or `manage_stock`). WooCommerce's own inventory management, low-stock
> notifications, and out-of-stock behaviour are completely unaffected.

---

## Table of Contents

1. [Requirements](#requirements)
2. [Installation](#installation)
3. [Configuration](#configuration)
4. [How It Works](#how-it-works)
5. [CSV Format](#csv-format)
6. [Reading the Meta Field](#reading-the-meta-field)
7. [Hooks & Filters](#hooks--filters)
8. [File Structure](#file-structure)
9. [Frequently Asked Questions](#frequently-asked-questions)

---

## Requirements

| Requirement | Minimum version |
|---|---|
| WordPress | 6.0 |
| WooCommerce | 7.0 |
| PHP | 7.4 |

---

## Installation

1. Copy the `wp-multi-magazzino/` directory into `wp-content/plugins/`.
2. In the WordPress admin, go to **Plugins → Installed Plugins**.
3. Activate **Woo Multi Stock**.
4. The plugin will appear as **Multi Stock** under the **WooCommerce** menu.

---

## Configuration

Navigate to **WooCommerce → Multi Stock** and fill in:

| Field | Description |
|---|---|
| **Warehouse Name** | A display label for this warehouse (used for your own reference). |
| **CSV Remote URL** | The full `https://` URL of the remote CSV file. Must be reachable from the server, not just your local machine. |

Click **Save Settings** before running a sync.

---

## How It Works

```
[Admin clicks "Start Sync"]
        │
        ▼
AJAX: woo_multi_stock_download
  • WordPress fetches the CSV via wp_remote_get()
  • Parses every row (skips header, converts quantity format)
  • Caches all rows in a WordPress transient (TTL: 1 hour)
  • Returns: { total: N }
        │
        ▼  (loop until is_done === true)
AJAX: woo_multi_stock_process_batch  (offset = 0, 50, 100 …)
  • Reads rows[offset … offset+49] from the transient
  • For each row: looks up the SKU with wc_get_product_id_by_sku()
    ├── Found  → update_post_meta( id, '_stock_CMT', qty )
    └── Not found → increments the "not found" counter
  • Returns: { processed, not_found, updated, next_offset, is_done }
        │
        ▼
UI updates progress bar and counters after every batch.
Final summary: "Processed X products, Y SKUs not found, Z SKUs updated."
```

### Batch size

Each AJAX request processes **50 rows**. This keeps individual requests well
within PHP's `max_execution_time` on shared hosting while minimising total
request count for large files.

### Transient caching

The CSV is downloaded **once** per sync session and stored in a WordPress
transient (`woo_multi_stock_csv_rows`, TTL 1 hour). If the sync takes longer
than 1 hour or the transient is cleared by a caching plugin mid-sync, clicking
**Start Sync** again will re-download the file and restart from the beginning.

---

## CSV Format

- **Delimiter:** semicolon (`;`)
- **First row:** header — always skipped automatically
- **Column 0:** SKU (must match a WooCommerce product or variation SKU exactly)
- **Column 1:** Quantity — uses a **comma** as the decimal separator

### Example file

```
SKU;QTY
01.02.0317;1,0000000000000
01.02.0318;24,0000000000000
01.02.0319;0,0000000000000
01.02.9999;5,5000000000000
```

### Quantity conversion

The quantity is converted from the European decimal format to an integer:

```
"1,0000000000000"  →  str_replace(',', '.')  →  "1.0000000000000"
                   →  (float)                →  1.0
                   →  (int)                  →  1
```

Decimal quantities are **truncated** (not rounded):
`"5,5000000000000"` → `5`.

---

## Reading the Meta Field

Once synced, the quantity is stored as a plain integer in `wp_postmeta`:

```php
// Get the CMT stock for a product.
$cmt_stock = (int) get_post_meta( $product_id, '_stock_CMT', true );

// In a WC_Product context.
$product   = wc_get_product( $product_id );
$cmt_stock = (int) $product->get_meta( '_stock_CMT' );
```

You can also reference the meta key constant directly:

```php
use WooMultiStock\Stock_Updater;

$cmt_stock = (int) get_post_meta( $product_id, Stock_Updater::META_KEY, true );
```

---

## Hooks & Filters

### `woo_multi_stock_before_processing` *(action)*

Fires at the beginning of every batch, before any `update_post_meta()` calls.

```php
/**
 * @param int $total_rows  Total number of rows in the CSV (all batches combined).
 */
add_action( 'woo_multi_stock_before_processing', function( int $total_rows ): void {
    error_log( 'WMS: starting batch. Total rows in CSV: ' . $total_rows );
} );
```

---

### `woo_multi_stock_after_row_update` *(action)*

Fires after each successful `update_post_meta()` call — i.e. once per matched
SKU. Useful for logging, triggering webhooks, or writing additional meta fields.

> **Note:** The third argument to `add_action()` must be `3` to receive all
> three parameters.

```php
/**
 * @param int    $product_id  Post ID of the updated product or variation.
 * @param string $sku         The SKU matched from the CSV.
 * @param int    $qty         The quantity written to _stock_CMT.
 */
add_action( 'woo_multi_stock_after_row_update', function( int $product_id, string $sku, int $qty ): void {
    // Example: also store the timestamp of the last CMT update.
    update_post_meta( $product_id, '_stock_CMT_updated_at', current_time( 'mysql' ) );
}, 10, 3 );
```

---

### `woo_multi_stock_download_timeout` *(filter)*

Controls the HTTP timeout (in seconds) used when downloading the CSV with
`wp_remote_get()`. Default: `60`. Increase this for very large files or
slow remote servers.

```php
/**
 * @param int $seconds  Timeout in seconds (default 60).
 * @return int
 */
add_filter( 'woo_multi_stock_download_timeout', function( int $seconds ): int {
    return 120; // Allow up to 2 minutes for the download.
} );
```

---

## File Structure

```
wp-multi-magazzino/
│
├── woo-multi-stock.php        Main plugin file: headers, PSR-4 autoloader,
│                              HPOS compatibility, bootstrap on plugins_loaded.
│
├── includes/
│   ├── Class-Admin.php        Admin settings page (WordPress Settings API),
│   │                          asset enqueueing, sync UI rendering.
│   │
│   ├── Class-Processor.php    AJAX handlers: CSV download, transient caching,
│   │                          batch processing loop, JSON responses.
│   │
│   └── Class-Stock-Updater.php  SKU lookup (wc_get_product_id_by_sku) and
│                                  update_post_meta(_stock_CMT) write.
│
├── assets/
│   └── admin-script.js        jQuery AJAX loop, progress bar, live counters,
│                              error handling, sync summary.
│
├── index.php                  Empty security file ("Silence is golden").
├── .gitignore                 Standard WordPress plugin gitignore.
└── README.md                  This file.
```

### Namespace

All PHP classes are in the `WooMultiStock` namespace and loaded by the inline
PSR-4-style autoloader in `woo-multi-stock.php`:

| Class | File |
|---|---|
| `WooMultiStock\Admin` | `includes/Class-Admin.php` |
| `WooMultiStock\Processor` | `includes/Class-Processor.php` |
| `WooMultiStock\Stock_Updater` | `includes/Class-Stock-Updater.php` |

### WordPress options stored

| Option key | Type | Description |
|---|---|---|
| `woo_multi_stock_warehouse_name` | `string` | Display name for the warehouse |
| `woo_multi_stock_csv_url` | `string` | Remote CSV URL |

---

## Frequently Asked Questions

**Does this plugin change WooCommerce stock levels?**
No. It writes only to `_stock_CMT`. The native `_stock`, `_stock_status`, and
`manage_stock` meta fields are never touched.

**Does it work with variable products and variations?**
Yes. `wc_get_product_id_by_sku()` returns the post ID of any product type,
including individual variations. The meta is written to whichever post ID
matches the SKU.

**What happens if the same SKU appears twice in the CSV?**
Both rows are processed. The second write overwrites the first. The final
value of `_stock_CMT` will be the quantity from the **last** occurrence of
the SKU in the file.

**What if the CSV URL is password-protected?**
`wp_remote_get()` supports HTTP Basic Authentication via the `headers` argument.
You can extend the download request using the `woo_multi_stock_download_timeout`
filter pattern as a reference, or use a child plugin that hooks into
`pre_http_request` to inject credentials.

**Can I increase the batch size beyond 50?**
The batch size is defined as `BATCH_SIZE = 50` in `Class-Processor.php` and
mirrored in `Admin::enqueue_assets()` as `wmsData.batchSize`. Change both
values to the same number. Larger batches reduce total request count but
increase per-request execution time — test on your specific hosting environment.

**The sync stops mid-way. What should I do?**
The CSV rows are cached in a WordPress transient for 1 hour. If your caching
plugin clears transients mid-sync, the next batch request will return an error
("CSV data has expired"). Click **Start Sync** again to re-download and restart.

---

*Plugin developed by [Mavida s.n.c.](https://mavida.com)*
