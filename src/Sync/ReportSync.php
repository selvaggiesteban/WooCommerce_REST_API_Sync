<?php
/**
 * Report Sync Module
 *
 * @package WooCommerceApiSync\Sync
 */

namespace WooCommerceApiSync\Sync;

defined('ABSPATH') || exit;

class ReportSync extends AbstractSync
{
    protected string $domain = 'reports';
    protected string $endpoint = 'reports';

    protected function get_external_id(array $item): string
    {
        return $item['slug'] ?? '';
    }

    protected function is_modified(array $item, object $existing): bool
    {
        return true;
    }

    public function get_sales_report(array $params = []): array
    {
        return $this->api->get('reports/sales', $params);
    }

    public function get_top_sellers(array $params = []): array
    {
        return $this->api->get('reports/top_sellers', $params);
    }

    public function get_coupon_totals(): array
    {
        return $this->api->get('reports/coupons/totals');
    }

    public function get_customer_totals(): array
    {
        return $this->api->get('reports/customers/totals');
    }

    public function get_order_totals(): array
    {
        return $this->api->get('reports/orders/totals');
    }

    public function get_product_totals(): array
    {
        return $this->api->get('reports/products/totals');
    }

    public function get_review_totals(): array
    {
        return $this->api->get('reports/reviews/totals');
    }

    public function get_all_reports(): array
    {
        return $this->api->get('reports');
    }
}
