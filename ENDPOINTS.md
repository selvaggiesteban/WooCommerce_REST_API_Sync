# WooCommerce REST API Sync — Endpoint Reference

## Overview

This plugin provides bidirectional synchronization for **all WooCommerce REST API v3 domains**. Below is the complete reference for each sync module, including supported endpoints, methods, data properties, and expected responses.

---

## 1. Products (`/products`)

### Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/products` | List all products |
| GET | `/products/{id}` | Get single product |
| POST | `/products` | Create product |
| PUT | `/products/{id}` | Update product |
| DELETE | `/products/{id}` | Delete product |
| POST | `/products/batch` | Batch create/update/delete |
| GET | `/products/{id}/variations` | List product variations |
| POST | `/products/{id}/variations` | Create variation |
| PUT | `/products/{id}/variations/{var_id}` | Update variation |
| DELETE | `/products/{id}/variations/{var_id}` | Delete variation |

### Data Properties

```json
{
  "id": 123,
  "name": "Product Name",
  "slug": "product-name",
  "type": "simple|variable|grouped|external",
  "status": "publish|draft|pending|private",
  "featured": true,
  "catalog_visibility": "visible|catalog|search|hidden",
  "description": "<p>Long description</p>",
  "short_description": "<p>Short desc</p>",
  "sku": "PRODUCT-SKU-001",
  "regular_price": "29.99",
  "sale_price": "19.99",
  "date_on_sale_from": "2024-01-01T00:00:00",
  "date_on_sale_to": "2024-12-31T23:59:59",
  "price": "29.99",
  "price_html": "<span>$29.99</span>",
  "on_sale": false,
  "purchasable": true,
  "virtual": false,
  "downloadable": false,
  "tax_status": "taxable|shipping|none",
  "tax_class": "",
  "manage_stock": true,
  "stock_quantity": 100,
  "stock_status": "instock|outofstock|onbackorder",
  "backorders": "yes|no|notify",
  "sold_individually": false,
  "weight": "0.5",
  "dimensions": {
    "length": "10",
    "width": "5",
    "height": "3"
  },
  "shipping_class": "",
  "reviews_allowed": true,
  "categories": [
    {"id": 1, "name": "Category", "slug": "category"}
  ],
  "tags": [
    {"id": 1, "name": "Tag", "slug": "tag"}
  ],
  "images": [
    {
      "id": 1,
      "src": "https://example.com/image.jpg",
      "name": "image.jpg",
      "alt": "Image alt text"
    }
  ],
  "attributes": [
    {
      "id": 1,
      "name": "Color",
      "position": 0,
      "visible": true,
      "variation": true,
      "options": ["Red", "Blue", "Green"]
    }
  ],
  "meta_data": [
    {"id": 1, "key": "_custom_field", "value": "custom_value"}
  ],
  "date_created": "2024-01-01T00:00:00",
  "date_modified": "2024-01-15T12:00:00"
}
```

### Stock Update Flow

```
External System → Plugin → WC REST API PUT /products/{id}
                          → Updates stock_quantity, manage_stock, stock_status
                          → Logs to wc_sync_state table
```

Stock updates are handled via:
- **Full Sync**: Pulls all products, compares `date_modified`, updates if changed
- **Webhook**: `product.updated` triggers immediate sync
- **Batch**: `POST /products/batch` with `update` array

### Product Sync Module: `ProductSync`

| Method | Description |
|--------|-------------|
| `sync_product(array $product)` | Sync single product with variations |
| `create_product(array $data)` | Create product in WC |
| `update_product(int $wc_id, array $data)` | Update existing product |
| `delete_product(int $wc_id, bool $force)` | Delete product |
| `get_product_by_sku(string $sku)` | Lookup by SKU |
| `batch_sync(array $products)` | Batch sync multiple products |
| `transform_to_wc(array $data)` | External format → WC format |
| `transform_from_wc(array $wc_data)` | WC format → External format |

---

## 2. Orders (`/orders`)

### Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/orders` | List all orders |
| GET | `/orders/{id}` | Get single order |
| POST | `/orders` | Create order |
| PUT | `/orders/{id}` | Update order |
| DELETE | `/orders/{id}` | Delete order |
| POST | `/orders/batch` | Batch create/update/delete |
| POST | `/orders/{id}/notes` | Add order note |
| GET | `/orders/{id}/refunds` | List refunds |
| POST | `/orders/{id}/refunds` | Create refund |

### Data Properties

```json
{
  "id": 456,
  "order_key": "wc_order_abc123",
  "number": "456",
  "status": "pending|processing|on-hold|completed|cancelled|refunded|failed",
  "currency": "USD",
  "date_created": "2024-01-15T10:30:00",
  "date_modified": "2024-01-15T11:00:00",
  "date_completed": "2024-01-15T12:00:00",
  "discount_total": "5.00",
  "discount_tax": "0.00",
  "shipping_total": "10.00",
  "shipping_tax": "0.00",
  "cart_tax": "3.50",
  "total": "88.50",
  "total_tax": "3.50",
  "prices_include_tax": "yes",
  "customer_id": 789,
  "customer_note": "Leave at door",
  "billing": {
    "first_name": "John",
    "last_name": "Doe",
    "company": "Acme Inc",
    "address_1": "123 Main St",
    "address_2": "Apt 4",
    "city": "New York",
    "state": "NY",
    "postcode": "10001",
    "country": "US",
    "email": "john@example.com",
    "phone": "+1234567890"
  },
  "shipping": {
    "first_name": "John",
    "last_name": "Doe",
    "company": "Acme Inc",
    "address_1": "123 Main St",
    "address_2": "Apt 4",
    "city": "New York",
    "state": "NY",
    "postcode": "10001",
    "country": "US"
  },
  "line_items": [
    {
      "id": 1,
      "name": "Product Name",
      "product_id": 123,
      "variation_id": 0,
      "quantity": 2,
      "sku": "PRODUCT-SKU-001",
      "price": "29.99",
      "subtotal": "59.98",
      "total": "59.98"
    }
  ],
  "shipping_lines": [
    {
      "id": 1,
      "method_id": "flat_rate",
      "method_title": "Flat Rate",
      "total": "10.00"
    }
  ],
  "fee_lines": [],
  "coupon_lines": [
    {
      "id": 1,
      "code": "SUMMER10",
      "discount": "5.00"
    }
  ],
  "payment_method": "bacs",
  "payment_method_title": "Direct Bank Transfer",
  "meta_data": []
}
```

### Order Sync Module: `OrderSync`

| Method | Description |
|--------|-------------|
| `sync_order(array $order)` | Sync single order |
| `create_order(array $data)` | Create order in WC |
| `update_order(int $wc_id, array $data)` | Update order |
| `update_status(int $wc_id, string $status)` | Update order status only |
| `add_note(int $wc_id, string $note, bool $customer_note)` | Add order note |
| `create_refund(int $wc_id, array $refund_data)` | Create refund |
| `get_by_status($status, int $limit)` | Filter by status |
| `get_modified_after(string $date)` | Get orders modified after date |
| `batch_sync(array $orders)` | Batch sync orders |

### Valid Order Statuses

`pending`, `processing`, `on-hold`, `completed`, `cancelled`, `refunded`, `failed`

---

## 3. Customers (`/customers`)

### Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/customers` | List all customers |
| GET | `/customers/{id}` | Get single customer |
| POST | `/customers` | Create customer |
| PUT | `/customers/{id}` | Update customer |
| DELETE | `/customers/{id}` | Delete customer |
| POST | `/customers/batch` | Batch create/update/delete |

### Data Properties

```json
{
  "id": 789,
  "email": "john@example.com",
  "first_name": "John",
  "last_name": "Doe",
  "username": "johndoe",
  "password": "",
  "billing": {
    "first_name": "John",
    "last_name": "Doe",
    "company": "Acme Inc",
    "address_1": "123 Main St",
    "address_2": "Apt 4",
    "city": "New York",
    "state": "NY",
    "postcode": "10001",
    "country": "US",
    "email": "john@example.com",
    "phone": "+1234567890"
  },
  "shipping": {
    "first_name": "John",
    "last_name": "Doe",
    "company": "Acme Inc",
    "address_1": "123 Main St",
    "address_2": "Apt 4",
    "city": "New York",
    "state": "NY",
    "postcode": "10001",
    "country": "US"
  },
  "is_paying_customer": false,
  "orders_count": 5,
  "total_spent": "250.00",
  "date_created": "2024-01-01T00:00:00",
  "date_modified": "2024-01-15T12:00:00",
  "meta_data": []
}
```

### Customer Sync Module: `CustomerSync`

| Method | Description |
|--------|-------------|
| `create_customer(array $data)` | Create customer in WC |
| `update_customer(int $wc_id, array $data)` | Update customer |
| `get_customer_by_email(string $email)` | Lookup by email |

**External ID**: `email` (used as unique identifier)

---

## 4. Coupons (`/coupons`)

### Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/coupons` | List all coupons |
| GET | `/coupons/{id}` | Get single coupon |
| POST | `/coupons` | Create coupon |
| PUT | `/coupons/{id}` | Update coupon |
| DELETE | `/coupons/{id}` | Delete coupon |
| POST | `/coupons/batch` | Batch create/update/delete |

### Data Properties

```json
{
  "id": 101,
  "code": "SUMMER10",
  "discount_type": "percent|fixed_cart|fixed_product",
  "amount": "10",
  "individual_use": true,
  "exclude_sale_items": false,
  "minimum_amount": "50",
  "maximum_amount": "500",
  "usage_limit": 100,
  "usage_limit_per_user": 1,
  "limit_usage_to_x_items": null,
  "free_shipping": false,
  "product_categories": [],
  "excluded_product_categories": [],
  "products": [],
  "excluded_products": [],
  "email_restrictions": ["*@example.com"],
  "description": "Summer 10% discount",
  "date_expires": "2024-12-31T23:59:59",
  "meta_data": []
}
```

**External ID**: `code` (coupon code)

---

## 5. Tax Rates (`/taxes`)

### Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/taxes` | List all tax rates |
| GET | `/taxes/{id}` | Get single tax rate |
| POST | `/taxes` | Create tax rate |
| PUT | `/taxes/{id}` | Update tax rate |
| DELETE | `/taxes/{id}` | Delete tax rate |
| GET | `/taxes/classes` | List tax classes |
| POST | `/taxes/classes` | Create tax class |
| DELETE | `/taxes/classes/{slug}` | Delete tax class |

### Data Properties

```json
{
  "id": 1,
  "country": "US",
  "state": "NY",
  "postcode": "10001",
  "city": "New York",
  "postcodes": [],
  "cities": [],
  "rate": "8.875",
  "name": "NY Sales Tax",
  "priority": 1,
  "compound": false,
  "shipping": false,
  "order": 1,
  "class": "standard"
}
```

---

## 6. Shipping Zones (`/shipping/zones`)

### Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/shipping/zones` | List all zones |
| GET | `/shipping/zones/{id}` | Get single zone |
| POST | `/shipping/zones` | Create zone |
| PUT | `/shipping/zones/{id}` | Update zone |
| DELETE | `/shipping/zones/{id}` | Delete zone |
| GET | `/shipping/zones/{id}/methods` | List zone methods |
| POST | `/shipping/zones/{id}/methods` | Add zone method |
| PUT | `/shipping/zones/{id}/methods/{method_id}` | Update method |
| DELETE | `/shipping/zones/{id}/methods/{method_id}` | Delete method |
| GET | `/shipping_methods` | List available methods |

### Data Properties

```json
{
  "id": 1,
  "name": "US",
  "order": 1,
  "locations": [
    {
      "code": "US:NY",
      "type": "state"
    }
  ],
  "method_ids": [1, 2]
}
```

---

## 7. Payment Gateways (`/payment_gateways`)

### Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/payment_gateways` | List all gateways |
| GET | `/payment_gateways/{id}` | Get single gateway |
| PUT | `/payment_gateways/{id}` | Update gateway |

### Data Properties

```json
{
  "id": "bacs",
  "title": "Direct Bank Transfer",
  "description": "Make your payment directly...",
  "order": 1,
  "enabled": true,
  "method_title": "Direct Bank Transfer",
  "method_description": "...",
  "supports": ["products"],
  "settings": {}
}
```

**Note**: Payment gateways are read-only sync. Enables/disables gateways remotely.

---

## 8. Reports (`/reports`)

### Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/reports` | List all reports |
| GET | `/reports/sales` | Sales report |
| GET | `/reports/top_sellers` | Top sellers |
| GET | `/reports/coupons/totals` | Coupon totals |
| GET | `/reports/customers/totals` | Customer totals |
| GET | `/reports/orders/totals` | Order totals |
| GET | `/reports/products/totals` | Product totals |
| GET | `/reports/reviews/totals` | Review totals |

### Report Data Structure

```json
{
  "sales": {
    "total_sales": "10000.00",
    "average_sales": "333.33",
    "total_orders": 100,
    "total_items": 250,
    "total_tax": "800.00",
    "total_shipping": "500.00",
    "total_refunds": "200.00",
    "total_discount": "300.00",
    "totals": {}
  }
}
```

---

## 9. Settings (`/settings`)

### Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/settings` | List setting groups |
| GET | `/settings/{group}` | List settings in group |
| GET | `/settings/{group}/{id}` | Get single setting |
| PUT | `/settings/{group}/{id}` | Update setting |
| POST | `/settings/{group}/batch` | Batch update settings |

---

## 10. Webhooks (`/webhooks`)

### Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/webhooks` | List all webhooks |
| GET | `/webhooks/{id}` | Get single webhook |
| POST | `/webhooks` | Create webhook |
| PUT | `/webhooks/{id}` | Update webhook |
| DELETE | `/webhooks/{id}` | Delete webhook |

### Supported Webhook Topics

```
order.created, order.updated, order.deleted
product.created, product.updated, product.deleted
customer.created, customer.updated, customer.deleted
coupon.created, coupon.updated, coupon.deleted
```

---

## 11. Media (`/media`)

### Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/media/{id}` | Get media item |
| POST | `/media` | Upload media |
| DELETE | `/media/{id}` | Delete media |

### Image Processing Pipeline

```
External URL → Download → Optimize (GD Library) → Convert WebP → Upload to WC
```

| Step | Description |
|------|-------------|
| Download | Fetch image from external URL |
| Optimize | Compress with configurable quality (1-100) |
| WebP | Convert to WebP format (if enabled) |
| Upload | POST to WC media endpoint |

### ImageOptimizer Configuration

| Setting | Default | Description |
|---------|---------|-------------|
| `image_optimization` | `true` | Enable/disable optimization |
| `image_webp` | `true` | Enable WebP conversion |
| `image_quality` | `80` | Compression quality (1-100) |

---

## Plugin-Specific REST API Endpoints

### Trigger Sync

```
POST /wp-json/wc-api-sync/v1/sync/{domain}
```

| Domain | Description |
|--------|-------------|
| `products` | Sync all products |
| `orders` | Sync all orders |
| `customers` | Sync all customers |
| `taxes` | Sync all tax rates |
| `shipping` | Sync shipping zones |
| `payments` | Sync payment gateways |
| `coupons` | Sync all coupons |
| `reports` | Sync reports |
| `settings` | Sync settings |
| `webhooks` | Sync webhooks |
| `media` | Sync media/images |

**Authentication**: Requires `manage_woocommerce` capability.

**Response**:
```json
{
  "success": true,
  "job_id": 123,
  "message": "Sync job queued for products"
}
```

### Webhook Receiver

```
POST /wp-json/wc-api-sync/v1/webhook/{topic}
```

**Authentication**: HMAC-SHA256 signature via `X-WC-Webhook-Signature` header.

**Signature computation**:
```php
$signature = base64_encode(hash_hmac('sha256', $request_body, $webhook_secret, true));
```

**Headers**:
- `X-WC-Webhook-Signature`: Base64-encoded HMAC-SHA256 signature
- `X-WC-Webhook-Test`: Set to `true` for test webhooks (skips signature check)

---

## Queue System

### Database Tables

| Table | Purpose |
|-------|---------|
| `wp_wc_sync_state` | Tracks sync state per item (domain + external_id → wc_id) |
| `wp_wc_sync_queue` | Async job queue with priority and retry logic |
| `wp_wc_sync_mappings` | Field mapping between external and WC formats |

### Job Types

| Type | Description |
|------|-------------|
| `full_sync` | Full domain synchronization |
| `incremental_sync` | Sync only modified items |
| `batch_sync` | Batch create/update/delete |
| `webhook_process` | Process incoming webhook |

### Job Lifecycle

```
pending → processing → completed
                     → failed → retry (up to 3 attempts)
```

---

## Configuration Options

| Key | Default | Description |
|-----|---------|-------------|
| `store_url` | Site URL | WooCommerce store URL |
| `consumer_key` | (empty) | WC REST API consumer key |
| `consumer_secret` | (empty) | WC REST API consumer secret |
| `api_version` | `wc/v3` | WC REST API version |
| `sync_mode` | `bidirectional` | `push`, `pull`, or `bidirectional` |
| `rate_limit_max` | `5` | Max concurrent API requests |
| `rate_limit_delay` | `100` | Delay between requests (ms) |
| `batch_size` | `100` | Items per batch request |
| `image_optimization` | `true` | Enable image optimization |
| `image_webp` | `true` | Enable WebP conversion |
| `image_quality` | `80` | Image compression quality |
| `log_level` | `info` | Log level: debug, info, warning, error |
| `full_sync_interval` | `6` | Hours between full syncs |
| `incremental_sync_interval` | `5` | Minutes between incremental syncs |
| `debug_mode` | `false` | Enable verbose logging |
