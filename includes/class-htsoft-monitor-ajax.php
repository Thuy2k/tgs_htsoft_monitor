<?php
/**
 * TGS_HTSoft_Monitor_Ajax
 *
 * Routing tập trung cho tất cả AJAX của plugin.
 *
 * Cách thêm action mới:
 *  1. Thêm tên action vào ADMIN_ACTIONS hoặc STAFF_ACTIONS bên dưới
 *  2. Viết private static method tên = phần sau "tgs_htsoft_monitor_"
 *     VD: tgs_htsoft_monitor_export → private static function export()
 *  3. Xong — dispatch() tự route, không cần sửa thêm chỗ nào
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_HTSoft_Monitor_Ajax
{
    // Actions chỉ dành cho admin (require manage_options + monitor nonce)
    private const ADMIN_ACTIONS = [
        'tgs_htsoft_monitor_get_logs',
        'tgs_htsoft_monitor_get_detail',
        'tgs_htsoft_monitor_resolve',
        'tgs_htsoft_monitor_soft_delete',
    ];

    // Actions mọi nhân viên đã đăng nhập đều dùng được (require is_user_logged_in)
    // Nonce linh hoạt: chấp nhận cả tmd_pos_nonce lẫn tgs_htsoft_monitor_nonce
    private const STAFF_ACTIONS = [
        'tgs_htsoft_monitor_upload_image',
    ];

    // ─── Boot ─────────────────────────────────────────────────────────────────

    public static function init(): void
    {
        $all = array_merge(self::ADMIN_ACTIONS, self::STAFF_ACTIONS);
        foreach ($all as $action) {
            add_action('wp_ajax_' . $action, [self::class, 'dispatch']);
        }
    }

    // ─── Central dispatcher ───────────────────────────────────────────────────

    public static function dispatch(): void
    {
        $action = sanitize_key($_POST['action'] ?? $_GET['action'] ?? '');

        if (in_array($action, self::STAFF_ACTIONS, true)) {
            if (!is_user_logged_in()) {
                wp_send_json_error(['message' => 'Bạn cần đăng nhập.'], 403);
            }
        } elseif (in_array($action, self::ADMIN_ACTIONS, true)) {
            if (!current_user_can('manage_options')) {
                wp_send_json_error(['message' => 'Bạn không có quyền.'], 403);
            }
            check_ajax_referer('tgs_htsoft_monitor_nonce', 'nonce');
        } else {
            wp_send_json_error(['message' => 'Action không hợp lệ.'], 400);
        }

        // Route: "tgs_htsoft_monitor_get_logs" → get_logs()
        $method = str_replace('tgs_htsoft_monitor_', '', $action);
        if (method_exists(self::class, $method)) {
            self::$method();
        } else {
            wp_send_json_error(['message' => "Handler '{$method}' chưa được cài đặt."], 501);
        }
    }

    // ─── Helper: switch blog, chạy callback, rồi restore (multisite safe) ────

    /**
     * @template T
     * @param callable(): T $fn
     * @param int           $blog_id  0 = không switch
     * @return T
     */
    private static function with_blog(callable $fn, int $blog_id = 0): mixed
    {
        $switched = $blog_id && $blog_id !== get_current_blog_id();
        if ($switched) {
            switch_to_blog($blog_id);
        }
        try {
            return $fn();
        } finally {
            if ($switched) {
                restore_current_blog();
            }
        }
    }

    // ─── Admin: danh sách log ─────────────────────────────────────────────────

    private static function get_logs(): void
    {
        $blog_id = intval($_POST['blog_id'] ?? 0);

        $result = self::with_blog(function () use ($blog_id) {
            return TGS_HTSoft_Monitor_DB::query([
                'blog_id'           => $blog_id ?: null,
                'resolution_status' => sanitize_key($_POST['resolution_status'] ?? ''),
                'has_price_diff'    => !empty($_POST['has_price_diff']),
                'has_unmatched'     => !empty($_POST['has_unmatched']),
                'date_from'         => sanitize_text_field($_POST['date_from'] ?? ''),
                'date_to'           => sanitize_text_field($_POST['date_to']   ?? ''),
                'per_page'          => intval($_POST['per_page'] ?? 30),
                'page'              => intval($_POST['page']     ?? 1),
            ]);
        }, $blog_id);

        foreach ($result['items'] as $item) {
            $item->shop_name = self::get_blog_name(intval($item->blog_id));
        }

        wp_send_json_success($result);
    }

    // ─── Admin: chi tiết 1 log ────────────────────────────────────────────────

    private static function get_detail(): void
    {
        $id      = intval($_POST['id']      ?? 0);
        $blog_id = intval($_POST['blog_id'] ?? 0);

        if (!$id) {
            wp_send_json_error(['message' => 'ID không hợp lệ.'], 400);
        }

        $row = self::with_blog(fn () => TGS_HTSoft_Monitor_DB::get($id), $blog_id);

        if (!$row) {
            wp_send_json_error(['message' => 'Không tìm thấy bản ghi.'], 404);
        }

        $json_cols = ['invoice_images', 'price_diff_items', 'unmatched_skus', 'selected_items', 'map_result'];
        foreach ($json_cols as $col) {
            if (!empty($row->{$col})) {
                $row->{$col} = json_decode($row->{$col}, true);
            }
        }

        $normalized = TGS_HTSoft_Monitor_Global_Products::normalize_log_payload([
            'price_diff_items' => is_array($row->price_diff_items) ? $row->price_diff_items : [],
            'selected_items' => is_array($row->selected_items) ? $row->selected_items : [],
            'map_result' => is_array($row->map_result) ? $row->map_result : [],
        ]);
        $row->price_diff_items = $normalized['price_diff_items'];
        $row->selected_items = $normalized['selected_items'];
        $row->map_result = $normalized['map_result'];

        wp_send_json_success($row);
    }

    // ─── Admin: cập nhật trạng thái xử lý ────────────────────────────────────

    private static function resolve(): void
    {
        $id      = intval($_POST['id']      ?? 0);
        $blog_id = intval($_POST['blog_id'] ?? 0);
        $status  = sanitize_key($_POST['status'] ?? 'pending');
        $note    = sanitize_textarea_field($_POST['note'] ?? '');

        if (!$id) {
            wp_send_json_error(['message' => 'ID không hợp lệ.'], 400);
        }

        $ok = self::with_blog(
            fn () => TGS_HTSoft_Monitor_DB::update_resolution($id, $status, $note, get_current_user_id()),
            $blog_id
        );

        $ok
            ? wp_send_json_success(['message' => 'Đã cập nhật trạng thái.'])
            : wp_send_json_error(['message' => 'Cập nhật thất bại.'], 500);
    }

    // ─── Admin: soft delete ───────────────────────────────────────────────────

    private static function soft_delete(): void
    {
        $id      = intval($_POST['id']      ?? 0);
        $blog_id = intval($_POST['blog_id'] ?? 0);

        if (!$id) {
            wp_send_json_error(['message' => 'ID không hợp lệ.'], 400);
        }

        $ok = self::with_blog(fn () => TGS_HTSoft_Monitor_DB::soft_delete($id), $blog_id);

        $ok
            ? wp_send_json_success(['message' => 'Đã xoá bản ghi.'])
            : wp_send_json_error(['message' => 'Xoá thất bại.'], 500);
    }

    // ─── Staff: upload ảnh bill ───────────────────────────────────────────────

    private static function upload_image(): void
    {
        // Chấp nhận nonce POS (tmd_pos_nonce) hoặc nonce admin (tgs_htsoft_monitor_nonce)
        $nonce = $_POST['nonce'] ?? '';
        if (!wp_verify_nonce($nonce, 'tmd_pos_nonce')
            && !wp_verify_nonce($nonce, 'tgs_htsoft_monitor_nonce')
        ) {
            wp_send_json_error(['message' => 'Nonce không hợp lệ.'], 403);
        }

        if (empty($_FILES['file'])) {
            wp_send_json_error(['message' => 'Không có file nào được gửi lên.'], 400);
        }

        $file    = $_FILES['file'];
        $blog_id = !empty($_POST['blog_id']) ? intval($_POST['blog_id']) : get_current_blog_id();

        // Validate MIME (extension + declared type phải khớp nhau)
        $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $checked = wp_check_filetype(basename($file['name']));
        if (!in_array($file['type'], $allowed, true) || !in_array($checked['type'], $allowed, true)) {
            wp_send_json_error(['message' => 'Chỉ chấp nhận file ảnh (JPG, PNG, GIF, WEBP).'], 400);
        }

        // Thư mục: wp-content/uploads/htsoft-invoices/{blog_id}/{YYYY-MM-DD}/
        // Luôn dùng thư mục uploads của main site (WP_CONTENT_DIR/uploads)
        // để tránh lỗi multisite khi wp_upload_dir() trả về sites/N/ chưa tồn tại.
        $upload_base_dir = WP_CONTENT_DIR . '/uploads';
        $upload_base_url = content_url('uploads');
        $rel_dir         = 'htsoft-invoices/' . $blog_id . '/' . gmdate('Y-m-d');
        $abs_dir         = $upload_base_dir . '/' . $rel_dir;

        if (!wp_mkdir_p($abs_dir)) {
            wp_send_json_error([
                'message' => 'Không tạo được thư mục upload: ' . $abs_dir
                    . ' (uploads writable: ' . (is_writable($upload_base_dir) ? 'yes' : 'no') . ')',
            ], 500);
        }

        $filename = wp_unique_filename($abs_dir, sanitize_file_name($file['name']));
        $dest     = $abs_dir . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            wp_send_json_error(['message' => 'Upload thất bại.'], 500);
        }

        wp_send_json_success([
            'url'      => $upload_base_url . '/' . $rel_dir . '/' . $filename,
            'filename' => $filename,
        ]);
    }

    // ─── Private helper ───────────────────────────────────────────────────────

    private static function get_blog_name(int $blog_id): string
    {
        if (!is_multisite()) {
            return get_bloginfo('name');
        }
        $info = get_blog_details($blog_id);
        return $info ? $info->blogname : "Shop #{$blog_id}";
    }
}
