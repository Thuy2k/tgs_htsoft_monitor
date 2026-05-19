<?php
/**
 * TGS_HTSoft_Monitor_Admin
 * Đăng ký menu + enqueue assets cho admin dashboard
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_HTSoft_Monitor_Admin
{
    const MENU_SLUG = 'tgs-htsoft-monitor';

    public static function init(): void
    {
        add_action('admin_menu',    [self::class, 'register_menu']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_assets']);
    }

    // ─── Admin menu ───────────────────────────────────────────────────────────

    public static function register_menu(): void
    {
        // Hook vào menu "Quản lý Shop" (tgs-shop-management)
        add_submenu_page(
            'tgs-shop-management',                  // parent slug
            'HTSoft Import Log',                    // page title
            '⚠ HTSoft Monitor',                    // menu label
            'manage_options',
            self::MENU_SLUG,
            [self::class, 'render_page']
        );
    }

    // ─── Enqueue assets ──────────────────────────────────────────────────────

    public static function enqueue_assets(string $hook): void
    {
        // Chỉ load trên trang plugin này
        if (strpos($hook, self::MENU_SLUG) === false) {
            return;
        }

        wp_enqueue_style(
            'tgs-htsoft-monitor-admin',
            TGS_HTSOFT_MONITOR_URL . 'assets/admin.css',
            [],
            TGS_HTSOFT_MONITOR_VERSION
        );

        wp_enqueue_script(
            'tgs-htsoft-monitor-admin',
            TGS_HTSOFT_MONITOR_URL . 'assets/admin.js',
            ['jquery'],
            TGS_HTSOFT_MONITOR_VERSION,
            true
        );

        wp_localize_script('tgs-htsoft-monitor-admin', 'tgsHtMonitor', [
            'ajaxUrl'  => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('tgs_htsoft_monitor_nonce'),
            'blogId'   => get_current_blog_id(),
            'blogs'    => self::get_all_blogs_list(),
        ]);
    }

    // ─── Page render ─────────────────────────────────────────────────────────

    public static function render_page(): void
    {
        include TGS_HTSOFT_MONITOR_DIR . 'templates/admin-dashboard.php';
    }

    // ─── Helper: list all blogs ───────────────────────────────────────────────

    public static function get_all_blogs_list(): array
    {
        if (!is_multisite()) {
            return [['id' => get_current_blog_id(), 'name' => get_bloginfo('name')]];
        }

        $sites = get_sites(['number' => 1000, 'fields' => 'ids']);
        $list  = [];

        foreach ($sites as $blog_id) {
            $details = get_blog_details($blog_id);
            if ($details) {
                $list[] = ['id' => $blog_id, 'name' => $details->blogname];
            }
        }

        return $list;
    }
}
