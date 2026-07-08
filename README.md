# WooCommerce REST API Sync

High-performance bidirectional synchronization engine for all WooCommerce domains.

## Features

- **Products** - Sync products, variations, categories, tags, attributes
- **Orders** - Sync orders, refunds, notes, status updates
- **Customers** - Sync customer data and addresses
- **Taxes** - Sync tax rates and classes
- **Shipping** - Sync shipping zones and methods
- **Payments** - Sync payment gateway configurations
- **Coupons** - Sync coupons and discounts
- **Reports** - Pull sales and analytics data
- **Settings** - Sync store configurations
- **Webhooks** - Manage webhook subscriptions
- **Media** - Upload and optimize images

## Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                    WC API Sync Architecture                  │
├─────────────────────────────────────────────────────────────┤
│  WooCommerce REST API v3 Client                              │
│       ↓                                                      │
│  Event Bus (Internal Communication)                          │
│       ↓                                                      │
│  Sync Modules (Products, Orders, etc.)                       │
│       ↓                                                      │
│  Queue System (Async Processing)                             │
│       ↓                                                      │
│  Webhook Receiver (Real-time Updates)                        │
│       ↓                                                      │
│  Image Optimizer (Compression + WebP)                        │
└─────────────────────────────────────────────────────────────┘
```

## Installation

1. Upload the `WooCommerce_REST_API_Sync` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu
3. Go to WooCommerce > API Sync to configure
4. Enter your WooCommerce REST API credentials

## Configuration

### API Settings

- **Store URL**: Your WooCommerce store URL
- **Consumer Key**: WooCommerce REST API consumer key
- **Consumer Secret**: WooCommerce REST API consumer secret

### Sync Settings

- **Sync Mode**: Bidirectional, Push Only, or Pull Only
- **Batch Size**: Number of items per batch request (max 100)

### Rate Limiting

- **Max Concurrent Requests**: Maximum parallel API requests
- **Delay Between Requests**: Milliseconds between requests

### Image Optimization

- **Enable Optimization**: Compress images during sync
- **WebP Conversion**: Convert images to WebP format
- **Image Quality**: Compression quality (1-100)

## Usage

### Manual Sync

1. Go to WooCommerce > API Sync
2. Click "Sync All Domains" button
3. Monitor progress in Logs section

### REST API

```bash
# Trigger sync for specific domain
POST /wp-json/wc-api-sync/v1/sync/products

# Trigger sync for all domains
POST /wp-json/wc-api-sync/v1/sync/all
```

### Webhooks

Configure webhooks to receive real-time updates:

```
Webhook URL: https://yourstore.com/wp-json/wc-api-sync/v1/webhook/order.created
Topic: order.created
Secret: Your webhook secret
```

## Auto-Updates

The plugin automatically checks for updates from GitHub:

- **Repository**: `selvaggiesteban/WooCommerce_REST_API_Sync`
- **Check Interval**: Every 12 hours
- **Branch**: `main`

## Development

### Prerequisites

- PHP 7.4+
- WordPress 6.0+
- WooCommerce 5.0+

### Setup

```bash
# Clone repository
git clone https://github.com/selvaggiesteban/WooCommerce_REST_API_Sync.git

# Install dependencies
composer install

# Run tests
composer test
```

### Project Structure

```
WooCommerce_REST_API_Sync/
├── src/
│   ├── Core/           # Plugin bootstrap, config, events
│   ├── API/            # WooCommerce REST API client
│   ├── Sync/           # Domain-specific sync modules
│   ├── Webhooks/       # Webhook handling
│   ├── Update/         # Auto-update system
│   ├── Queue/          # Job queue
│   ├── Image/          # Image optimization
│   └── Utils/          # Utility classes
├── config/             # Configuration files
├── templates/          # Admin templates
├── tests/              # Unit tests
├── composer.json       # Dependencies
└── wc-api-sync.php     # Main plugin file
```

## Database Tables

The plugin creates three custom tables:

- `wp_wc_sync_state` - Tracks sync state for each item
- `wp_wc_sync_queue` - Async job queue
- `wp_wc_sync_mappings` - Field mapping configuration

## Logging

Logs are stored in `wp-content/wc-api-sync.log`

Log levels: `debug`, `info`, `warning`, `error`

## Support

- **GitHub Issues**: https://github.com/selvaggiesteban/WooCommerce_REST_API_Sync/issues
- **Documentation**: https://github.com/selvaggiesteban/WooCommerce_REST_API_Sync/wiki

## License

GPL-2.0-or-later

## Author

**Esteban Selvaggi**
- Website: https://selvaggiesteban.dev
- GitHub: https://github.com/selvaggiesteban
