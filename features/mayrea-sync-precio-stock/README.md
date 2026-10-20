# Mayrea Sync Precio y Stock

Bundled feature plugin: one-way price/stock synchronization for a WooCommerce catalog from the
StProductos ERP endpoint (`https://aguilaapi.com/api/StProductos/sitio/dwaguila3g_mayrea/stock`).

## Features

- Field mapping: `codigoStProducto` -> SKU, `precioL4StConIva` -> regular price, `wStock` -> stock quantity (`0` => `outofstock`).
- Updates **existing products only** (never creates, never deletes).
- Scheduled 4x/day (00:00/06:00/12:00/18:00 ART) via Action Scheduler; batches of 300 items with a 15 s time budget, chained as async jobs.
- Safety: payload floor (>= 1000 valid records), 1 h execution lock, snapshot transient (3 h TTL), per-product try/catch, LiteSpeed cache purge on completion.
- Admin panel: WooCommerce -> "Sync Precio y Stock" (manual run with nonce + `manage_woocommerce` cap).
- WP-CLI: `wp mayrea-sync status` (read-only).
- Structured log source: `mayrea-sync` in WooCommerce -> Status -> Logs.

## Installation

Copy `mayrea-sync-precio-stock.php` into `wp-content/plugins/mayrea-sync-precio-stock/` and activate (requires WooCommerce).
