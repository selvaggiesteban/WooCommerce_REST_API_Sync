<?php
/**
 * Input Sanitizer
 *
 * @package WooCommerceApiSync\Utils
 */

namespace WooCommerceApiSync\Utils;

defined('ABSPATH') || exit;

class Sanitizer
{
    public static function sanitize_string(string $value): string
    {
        return sanitize_text_field($value);
    }

    public static function sanitize_email(string $value): string
    {
        return sanitize_email($value);
    }

    public static function sanitize_url(string $value): string
    {
        return esc_url_raw($value);
    }

    public static function sanitize_integer($value): int
    {
        return absint($value);
    }

    public static function sanitize_float($value): float
    {
        return (float) $value;
    }

    public static function sanitize_boolean($value): bool
    {
        return (bool) $value;
    }

    public static function sanitize_array(array $value, array $rules = []): array
    {
        $sanitized = [];
        
        foreach ($value as $key => $item) {
            $rule = $rules[$key] ?? 'string';
            
            switch ($rule) {
                case 'string':
                    $sanitized[$key] = self::sanitize_string($item);
                    break;
                case 'email':
                    $sanitized[$key] = self::sanitize_email($item);
                    break;
                case 'url':
                    $sanitized[$key] = self::sanitize_url($item);
                    break;
                case 'integer':
                    $sanitized[$key] = self::sanitize_integer($item);
                    break;
                case 'float':
                    $sanitized[$key] = self::sanitize_float($item);
                    break;
                case 'boolean':
                    $sanitized[$key] = self::sanitize_boolean($item);
                    break;
                case 'array':
                    $sanitized[$key] = is_array($item) ? $item : [];
                    break;
                default:
                    $sanitized[$key] = sanitize_text_field($item);
            }
        }
        
        return $sanitized;
    }

    public static function sanitize_product_data(array $data): array
    {
        return self::sanitize_array($data, [
            'name' => 'string',
            'type' => 'string',
            'status' => 'string',
            'sku' => 'string',
            'regular_price' => 'string',
            'sale_price' => 'string',
            'stock_quantity' => 'integer',
            'manage_stock' => 'boolean',
            'weight' => 'string',
            'description' => 'string',
            'short_description' => 'string',
        ]);
    }

    public static function sanitize_order_data(array $data): array
    {
        return self::sanitize_array($data, [
            'status' => 'string',
            'currency' => 'string',
            'customer_note' => 'string',
        ]);
    }

    public static function sanitize_customer_data(array $data): array
    {
        return self::sanitize_array($data, [
            'email' => 'email',
            'first_name' => 'string',
            'last_name' => 'string',
            'company' => 'string',
            'phone' => 'string',
        ]);
    }

    public static function sanitize_address(array $data): array
    {
        return self::sanitize_array($data, [
            'first_name' => 'string',
            'last_name' => 'string',
            'company' => 'string',
            'address_1' => 'string',
            'address_2' => 'string',
            'city' => 'string',
            'state' => 'string',
            'postcode' => 'string',
            'country' => 'string',
            'email' => 'email',
            'phone' => 'string',
        ]);
    }
}
