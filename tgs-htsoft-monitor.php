<?php
/**
 * Plugin Name: TGS HTSoft Monitor
 * Plugin URI:  https://tgs.vn
 * Description: Theo dõi log import hóa đơn HTSoft — cảnh báo lệch giá, SKU thiếu, ảnh bill, trạng thái xử lý.
 * Version:     1.0.0
 * Author:      TGS Dev
 * Text Domain: tgs-htsoft-monitor
 * Network:     false
 */

if (!defined('ABSPATH')) {
    exit;
}

define('TGS_HTSOFT_MONITOR_VERSION', '1.0.0');
define('TGS_HTSOFT_MONITOR_DIR',     plugin_dir_path(__FILE__));
define('TGS_HTSOFT_MONITOR_URL',     plugin_dir_url(__FILE__));

// Auto-load includes
require_once TGS_HTSOFT_MONITOR_DIR . 'includes/class-htsoft-monitor-global-products.php';
require_once TGS_HTSOFT_MONITOR_DIR . 'includes/class-htsoft-monitor-db.php';
require_once TGS_HTSOFT_MONITOR_DIR . 'includes/class-htsoft-monitor-ajax.php';
require_once TGS_HTSOFT_MONITOR_DIR . 'includes/class-htsoft-monitor-admin.php';

// Boot
add_action('plugins_loaded', function () {
    TGS_HTSoft_Monitor_Admin::init();
    TGS_HTSoft_Monitor_Ajax::init();
});

// ─── Tích hợp vào TGS Shop Admin ─────────────────────────────────────────────

/**
 * Đăng ký route htsoft-monitor vào TGS Shop dashboard router.
 * Khi user truy cập ?page=tgs-shop-management&view=htsoft-monitor,
 * router sẽ load template của plugin này qua absolute path.
 */
add_filter('tgs_shop_dashboard_routes', function (array $routes): array {
    $routes['htsoft-monitor'] = [
        'HTSoft Monitor',
        TGS_HTSOFT_MONITOR_DIR . 'templates/admin-dashboard.php',
    ];
    return $routes;
});

/**
 * Thêm nav item vào section "Báo cáo mở rộng" trong TGS Shop mega-nav.
 *
 * @param string $current_view View slug đang active
 */
add_action('tgs_shop_report_menu', function (string $current_view): void {
    $active = ($current_view === 'htsoft-monitor') ? 'active' : '';
    $url    = esc_url(admin_url('admin.php?page=tgs-shop-management&view=htsoft-monitor'));
    echo '<li><a href="' . $url . '" class="' . esc_attr($active) . '"><i class="bx bx-error-alt"></i>HTSoft Monitor</a></li>';
});

/**
 * Lắng nghe hook tgs_pos_order_committed (bắn sau khi đơn POS được commit thành công).
 * Nếu đơn có is_htsoft_import=1 thì ghi log vào local_htsoft_import_log.
 *
 * @param int    $sale_ledger_id
 * @param string $sale_code
 * @param array  $payload
 * @param array  $extra
 */
add_action('tgs_pos_order_committed', function ($sale_ledger_id, $sale_code, $payload, $extra) {
    // Đọc HTSoft data từ $extra (QR/deferred commit) hoặc $_POST (cash/transfer trực tiếp)
    $htsoft = $extra['htsoft'] ?? null;
    $is_htsoft = !empty($htsoft) || !empty($_POST['is_htsoft_import']);
    if (!$is_htsoft) {
        return;
    }

    if ($htsoft) {
        // Deferred commit qua WC gateway (VietinBank, v.v.): data được lưu trong $sale_args → $extra
        $invoice_images   = json_decode(stripslashes($htsoft['invoice_images']   ?? '[]'), true) ?: [];
        $map_result       = json_decode(stripslashes($htsoft['map_result']        ?? '[]'), true) ?: [];
        $price_diff_items = json_decode(stripslashes($htsoft['price_diff_items']  ?? '[]'), true) ?: [];
        $unmatched_skus   = json_decode(stripslashes($htsoft['unmatched_skus']    ?? '[]'), true) ?: [];
        $selected_items   = json_decode(stripslashes($htsoft['selected_items']    ?? '[]'), true) ?: [];
        $invoice_no       = sanitize_text_field($htsoft['invoice_no'] ?? '');
    } else {
        // Commit trực tiếp (tiền mặt / chuyển khoản không qua WC gateway): đọc từ $_POST
        $invoice_images   = json_decode(stripslashes($_POST['htsoft_invoice_images']   ?? '[]'), true) ?: [];
        $map_result       = json_decode(stripslashes($_POST['htsoft_map_result']        ?? '[]'), true) ?: [];
        $price_diff_items = json_decode(stripslashes($_POST['htsoft_price_diff_items']  ?? '[]'), true) ?: [];
        $unmatched_skus   = json_decode(stripslashes($_POST['htsoft_unmatched_skus']    ?? '[]'), true) ?: [];
        $selected_items   = json_decode(stripslashes($_POST['htsoft_selected_items']    ?? '[]'), true) ?: [];
        $invoice_no       = sanitize_text_field($_POST['htsoft_invoice_no'] ?? '');
    }

    $log_payload = TGS_HTSoft_Monitor_Global_Products::normalize_log_payload([
        'blog_id'           => get_current_blog_id(),
        'sale_id'           => intval($sale_ledger_id),
        'sale_code'         => $sale_code,
        'htsoft_invoice_no' => $invoice_no,
        'invoice_images'    => $invoice_images,
        'raw_ai_result'     => null,          // full AI result không cần thiết trong log
        'map_result'        => $map_result,
        'price_diff_items'  => $price_diff_items,
        'unmatched_skus'    => $unmatched_skus,
        'selected_items'    => $selected_items,
        'user_id'           => get_current_user_id(),
    ]);

    $insert_id = TGS_HTSoft_Monitor_DB::insert($log_payload);

    if (!$insert_id) {
        global $wpdb;
        error_log('[TGS HTSoft Monitor] insert FAILED — sale_code=' . $sale_code
            . ' blog=' . get_current_blog_id()
            . ' table=' . TGS_HTSoft_Monitor_DB::table()
            . ' db_error=' . $wpdb->last_error);
    }
}, 10, 4);
