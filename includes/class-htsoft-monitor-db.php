<?php
/**
 * TGS_HTSoft_Monitor_DB
 * Truy vấn + ghi bảng local_htsoft_import_log
 * Hỗ trợ multisite: luôn switch_to_blog() trước khi truy vấn per-shop
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_HTSoft_Monitor_DB
{
    /**
     * Tên bảng hiện tại (theo $wpdb->prefix của blog đang active)
     */
    public static function table(): string
    {
        global $wpdb;
        // Dùng base_prefix để bảng luôn thuộc main site (wp_local_htsoft_import_log)
        // không phụ thuộc blog context đang active trên multisite.
        return $wpdb->base_prefix . 'local_htsoft_import_log';
    }

    /**
     * Ghi một bản ghi log mới.
     *
     * @param array $data {
     *   blog_id, sale_id?, sale_code?, htsoft_invoice_no?,
     *   invoice_images (array), raw_ai_result (array), map_result (array),
     *   price_diff_items (array), unmatched_skus (array), selected_items (array),
     *   user_id?
     * }
     * @return int|false  insert ID hoặc false nếu lỗi
     */
    public static function insert(array $data)
    {
        global $wpdb;

        $price_diff_items  = $data['price_diff_items']  ?? [];
        $unmatched_skus    = $data['unmatched_skus']    ?? [];
        $selected_items    = $data['selected_items']    ?? [];
        $invoice_images    = $data['invoice_images']    ?? [];
        $raw_ai_result     = $data['raw_ai_result']     ?? null;
        $map_result        = $data['map_result']        ?? null;

        $row = [
            'blog_id'           => intval($data['blog_id'] ?? get_current_blog_id()),
            'sale_id'           => !empty($data['sale_id'])           ? intval($data['sale_id'])           : null,
            'sale_code'         => !empty($data['sale_code'])         ? sanitize_text_field($data['sale_code']) : null,
            'htsoft_invoice_no' => !empty($data['htsoft_invoice_no']) ? sanitize_text_field($data['htsoft_invoice_no']) : null,
            'invoice_images'    => wp_json_encode($invoice_images,    JSON_UNESCAPED_UNICODE),
            'raw_ai_result'     => $raw_ai_result ? wp_json_encode($raw_ai_result, JSON_UNESCAPED_UNICODE)  : null,
            'map_result'        => $map_result    ? wp_json_encode($map_result,    JSON_UNESCAPED_UNICODE)  : null,
            'price_diff_count'  => count($price_diff_items),
            'price_diff_items'  => wp_json_encode($price_diff_items,  JSON_UNESCAPED_UNICODE),
            'unmatched_count'   => count($unmatched_skus),
            'unmatched_skus'    => wp_json_encode($unmatched_skus,    JSON_UNESCAPED_UNICODE),
            'selected_items'    => wp_json_encode($selected_items,    JSON_UNESCAPED_UNICODE),
            'resolution_status' => 'pending',
            'user_id'           => !empty($data['user_id']) ? intval($data['user_id']) : get_current_user_id(),
            'created_at'        => current_time('mysql'),
            'updated_at'        => current_time('mysql'),
        ];

        $formats = [
            '%d', '%d', '%s', '%s', '%s', '%s', '%s',
            '%d', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s',
        ];

        $result = $wpdb->insert(self::table(), $row, $formats);
        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Cập nhật trạng thái giải quyết
     */
    public static function update_resolution(int $id, string $status, string $note, int $resolved_by): bool
    {
        global $wpdb;

        $allowed = ['pending', 'in_progress', 'resolved', 'ignored'];
        if (!in_array($status, $allowed, true)) {
            return false;
        }

        $result = $wpdb->update(
            self::table(),
            [
                'resolution_status' => $status,
                'resolution_note'   => sanitize_textarea_field($note),
                'resolved_by'       => $resolved_by,
                'resolved_at'       => in_array($status, ['resolved', 'ignored']) ? current_time('mysql') : null,
                'updated_at'        => current_time('mysql'),
            ],
            ['id' => $id],
            ['%s', '%s', '%d', '%s', '%s'],
            ['%d']
        );

        return $result !== false;
    }

    /**
     * Cập nhật sale_id & sale_code sau khi đơn hàng được tạo
     */
    public static function attach_sale(int $id, int $sale_id, string $sale_code): bool
    {
        global $wpdb;

        $result = $wpdb->update(
            self::table(),
            [
                'sale_id'    => $sale_id,
                'sale_code'  => sanitize_text_field($sale_code),
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $id],
            ['%d', '%s', '%s'],
            ['%d']
        );

        return $result !== false;
    }

    /**
     * Soft delete
     */
    public static function soft_delete(int $id): bool
    {
        global $wpdb;

        $result = $wpdb->update(
            self::table(),
            [
                'is_deleted' => 1,
                'deleted_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $id],
            ['%d', '%s', '%s'],
            ['%d']
        );

        return $result !== false;
    }

    /**
     * Lấy một bản ghi đầy đủ theo ID
     */
    public static function get(int $id): ?object
    {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM `" . self::table() . "` WHERE id = %d AND is_deleted = 0", $id)
        );
    }

    /**
     * Lấy danh sách logs có phân trang + filter
     *
     * @param array $args {
     *   blog_id?,               -- nếu null lấy tất cả blogs (multisite)
     *   resolution_status?,     -- 'pending' | 'in_progress' | 'resolved' | 'ignored' | ''
     *   has_price_diff?,        -- bool
     *   has_unmatched?,         -- bool
     *   date_from?,             -- Y-m-d
     *   date_to?,               -- Y-m-d
     *   per_page?,              -- default 30
     *   page?,                  -- default 1
     * }
     * @return array { items: object[], total: int }
     */
    public static function query(array $args = []): array
    {
        global $wpdb;

        $per_page = max(1, intval($args['per_page'] ?? 30));
        $page     = max(1, intval($args['page']     ?? 1));
        $offset   = ($page - 1) * $per_page;
        $table    = self::table();

        $where   = ['is_deleted = 0'];
        $binds   = [];

        if (!empty($args['blog_id'])) {
            $where[] = 'blog_id = %d';
            $binds[] = intval($args['blog_id']);
        }

        if (!empty($args['resolution_status'])) {
            $where[] = 'resolution_status = %s';
            $binds[] = $args['resolution_status'];
        }

        if (!empty($args['has_price_diff'])) {
            $where[] = 'price_diff_count > 0';
        }

        if (!empty($args['has_unmatched'])) {
            $where[] = 'unmatched_count > 0';
        }

        if (!empty($args['date_from'])) {
            $where[] = 'DATE(created_at) >= %s';
            $binds[] = sanitize_text_field($args['date_from']);
        }

        if (!empty($args['date_to'])) {
            $where[] = 'DATE(created_at) <= %s';
            $binds[] = sanitize_text_field($args['date_to']);
        }

        $where_sql = 'WHERE ' . implode(' AND ', $where);

        // Count total
        $count_sql = "SELECT COUNT(*) FROM `$table` $where_sql";
        $total     = (int) ($binds ? $wpdb->get_var($wpdb->prepare($count_sql, ...$binds)) : $wpdb->get_var($count_sql));

        // Fetch rows
        $limit_binds = array_merge($binds, [$per_page, $offset]);
        $data_sql    = "SELECT id, blog_id, sale_id, sale_code, htsoft_invoice_no,
                               price_diff_count, unmatched_count, resolution_status,
                               user_id, created_at, updated_at
                        FROM `$table` $where_sql
                        ORDER BY created_at DESC
                        LIMIT %d OFFSET %d";

        $items = $wpdb->get_results($wpdb->prepare($data_sql, ...$limit_binds));

        return ['items' => $items ?: [], 'total' => $total];
    }

    /**
     * Tổng hợp thống kê theo blog (tất cả shops)
     * Chạy trong context của blog hiện tại — gọi trên blog 1 để lấy toàn global
     */
    public static function stats_by_blog(): array
    {
        global $wpdb;

        $table = self::table();
        $rows  = $wpdb->get_results(
            "SELECT blog_id,
                    COUNT(*) AS total_logs,
                    SUM(price_diff_count > 0) AS logs_with_price_diff,
                    SUM(unmatched_count > 0)  AS logs_with_unmatched,
                    SUM(resolution_status = 'pending') AS pending_count,
                    SUM(resolution_status = 'resolved') AS resolved_count
             FROM `$table`
             WHERE is_deleted = 0
             GROUP BY blog_id
             ORDER BY pending_count DESC"
        );

        return $rows ?: [];
    }
}
