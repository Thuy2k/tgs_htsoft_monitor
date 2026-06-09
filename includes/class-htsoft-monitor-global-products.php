<?php
/**
 * Adapter san pham global cho TGS HTSoft Monitor.
 *
 * Plugin monitor chi luu log HTSoft, nhung khi can bo sung ten/gia san pham
 * thi phai doc qua TGS_Global_Product_Source cua tgs_shop_management.
 *
 * @package tgs_htsoft_monitor
 */

if (!defined('ABSPATH')) {
    exit;
}

final class TGS_HTSoft_Monitor_Global_Products
{
    private static $source_ready = null;

    public static function ensure_source(): bool
    {
        if (self::$source_ready !== null) {
            return (bool) self::$source_ready;
        }

        global $wpdb;

        if (!defined('TGS_TABLE_GLOBAL_PRODUCT_NAME')) {
            define('TGS_TABLE_GLOBAL_PRODUCT_NAME', $wpdb->base_prefix . 'global_product_name');
        }

        if (!class_exists('TGS_Global_Product_Source')) {
            $source_file = WP_PLUGIN_DIR . '/tgs_shop_management/functions/class-tgs-global-product-source.php';
            if (is_readable($source_file)) {
                require_once $source_file;
            }
        }

        self::$source_ready = class_exists('TGS_Global_Product_Source');
        return (bool) self::$source_ready;
    }

    public static function products_by_skus(array $skus): array
    {
        if (!self::ensure_source()) {
            return [];
        }

        $skus = self::normalize_skus($skus);
        if (!$skus) {
            return [];
        }

        $result = TGS_Global_Product_Source::query_products([
            'skus' => $skus,
            'per_page' => count($skus),
            'parent_only' => false,
            'status_filter' => 'all',
            'require_sku' => true,
            'with_local_aliases' => false,
        ]);

        $map = [];
        foreach ((array) ($result['items'] ?? []) as $product) {
            $product = (array) $product;
            $sku = self::sku($product);
            if ($sku === '') {
                continue;
            }
            $map[$sku] = $product;
            $map[strtoupper($sku)] = $product;
        }

        return $map;
    }

    public static function normalize_log_payload(array $payload): array
    {
        $skus = [];
        foreach (['price_diff_items', 'selected_items', 'map_result'] as $key) {
            foreach ((array) ($payload[$key] ?? []) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $sku = self::extract_item_sku($item);
                if ($sku !== '') {
                    $skus[] = $sku;
                }
            }
        }

        $products_by_sku = self::products_by_skus($skus);

        foreach (['price_diff_items', 'selected_items', 'map_result'] as $key) {
            $payload[$key] = self::enrich_item_list((array) ($payload[$key] ?? []), $products_by_sku);
        }

        return $payload;
    }

    public static function enrich_item_list(array $items, array $products_by_sku): array
    {
        $result = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                $result[] = $item;
                continue;
            }

            $sku = self::extract_item_sku($item);
            $product = $sku !== '' ? ($products_by_sku[$sku] ?? ($products_by_sku[strtoupper($sku)] ?? [])) : [];
            if ($product) {
                $product = (array) $product;
                $global_sku = self::sku($product);
                $global_name = self::name($product, $item['name'] ?? ($item['product_name'] ?? $global_sku));

                if (empty($item['sku']) && $global_sku !== '') {
                    $item['sku'] = $global_sku;
                }
                if (empty($item['mapped_sku']) && $global_sku !== '') {
                    $item['mapped_sku'] = $global_sku;
                }
                if (empty($item['name']) && $global_name !== '') {
                    $item['name'] = $global_name;
                }
                if (empty($item['product_name']) && $global_name !== '') {
                    $item['product_name'] = $global_name;
                }

                $item['global_product_name_id'] = (int) ($product['global_product_name_id'] ?? 0);
                $item['global_product_sku'] = $global_sku;
                $item['global_product_name'] = $global_name;
                $item['global_product_unit'] = (string) ($product['global_product_unit'] ?? '');

                if (!isset($item['db_price']) && isset($product['global_product_price_after_tax'])) {
                    $item['db_price'] = (float) $product['global_product_price_after_tax'];
                }
                if (!isset($item['db_price_after_tax']) && isset($product['global_product_price_after_tax'])) {
                    $item['db_price_after_tax'] = (float) $product['global_product_price_after_tax'];
                }
            }

            $result[] = $item;
        }

        return $result;
    }

    public static function extract_item_sku(array $item): string
    {
        foreach (['global_product_sku', 'mapped_sku', 'sku', 'htsoft_sku'] as $key) {
            $sku = trim((string) ($item[$key] ?? ''));
            if ($sku !== '') {
                return $sku;
            }
        }

        return '';
    }

    public static function normalize_skus(array $skus): array
    {
        return array_values(array_unique(array_filter(array_map(static function ($sku) {
            return trim((string) $sku);
        }, $skus), static function ($sku) {
            return $sku !== '';
        })));
    }

    public static function sku(array $product): string
    {
        return trim((string) ($product['global_product_sku'] ?? $product['sku'] ?? ''));
    }

    public static function name(array $product, string $fallback = ''): string
    {
        $name = trim((string) ($product['global_product_name'] ?? $product['product_name'] ?? $product['name'] ?? ''));
        return $name !== '' ? $name : $fallback;
    }
}
