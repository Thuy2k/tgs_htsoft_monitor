<?php
/**
 * HTSoft Monitor — Dashboard template
 * Rendered inside TGS Shop main-layout.php (Bootstrap 5 + Sneat + Boxicons already loaded).
 */
if (!defined('ABSPATH')) { exit; }
$_nonce    = wp_create_nonce('tgs_htsoft_monitor_nonce');
$_ajax_url = admin_url('admin-ajax.php');
$_blog_id  = get_current_blog_id();
$_blogs    = TGS_HTSoft_Monitor_Admin::get_all_blogs_list();
?>

<div id="tgs-htsoft-monitor-app">

    <!-- ─── Page header ─────────────────────────────────────────────────────── -->
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
        <h4 class="fw-bold mb-0">
            <span class="text-muted fw-light">Báo cáo /</span> HTSoft Monitor
        </h4>
        <button class="btn btn-sm btn-outline-secondary" id="btn-refresh">
            <i class="bx bx-refresh me-1"></i>Làm mới
        </button>
    </div>

    <!-- ─── Stats row ───────────────────────────────────────────────────────── -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="tgs-stat-card">
                <div class="stat-label"><i class="bx bx-time-five me-1 text-warning"></i>Chờ xử lý</div>
                <div class="stat-value" id="stat-pending">
                    <span class="placeholder-glow"><span class="placeholder col-5 rounded"></span></span>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="tgs-stat-card">
                <div class="stat-label"><i class="bx bx-money-withdraw me-1 text-danger"></i>Lệch giá</div>
                <div class="stat-value" id="stat-price-diff">
                    <span class="placeholder-glow"><span class="placeholder col-5 rounded"></span></span>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="tgs-stat-card">
                <div class="stat-label"><i class="bx bx-barcode me-1 text-info"></i>SKU thiếu</div>
                <div class="stat-value" id="stat-unmatched">
                    <span class="placeholder-glow"><span class="placeholder col-5 rounded"></span></span>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="tgs-stat-card">
                <div class="stat-label"><i class="bx bx-check-circle me-1 text-success"></i>Đã xử lý</div>
                <div class="stat-value" id="stat-resolved">
                    <span class="placeholder-glow"><span class="placeholder col-5 rounded"></span></span>
                </div>
            </div>
        </div>
    </div>

    <!-- ─── Filter card ─────────────────────────────────────────────────────── -->
    <div class="card mb-4">
        <div class="card-body py-3">
            <div class="row g-2 align-items-end">
                <div class="col-sm-6 col-md-2">
                    <label class="form-label small fw-semibold mb-1">Shop</label>
                    <select id="filter-blog" class="form-select form-select-sm">
                    </select>
                </div>
                <div class="col-sm-6 col-md-2">
                    <label class="form-label small fw-semibold mb-1">Trạng thái</label>
                    <select id="filter-status" class="form-select form-select-sm">
                        <option value="">Mọi trạng thái</option>
                        <option value="pending">Chờ xử lý</option>
                        <option value="in_progress">Đang xử lý</option>
                        <option value="resolved">Đã giải quyết</option>
                        <option value="ignored">Bỏ qua</option>
                    </select>
                </div>
                <div class="col-sm-6 col-md-2">
                    <label class="form-label small fw-semibold mb-1">Từ ngày</label>
                    <input type="date" id="filter-date-from" class="form-control form-control-sm">
                </div>
                <div class="col-sm-6 col-md-2">
                    <label class="form-label small fw-semibold mb-1">Đến ngày</label>
                    <input type="date" id="filter-date-to" class="form-control form-control-sm">
                </div>
                <div class="col-sm-6 col-md-2 pt-1">
                    <div class="form-check mb-1">
                        <input class="form-check-input" type="checkbox" id="filter-price-diff" value="1">
                        <label class="form-check-label small" for="filter-price-diff">Chỉ lệch giá</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="filter-unmatched" value="1">
                        <label class="form-check-label small" for="filter-unmatched">Chỉ SKU thiếu</label>
                    </div>
                </div>
                <div class="col-sm-6 col-md-2">
                    <div class="d-flex gap-2">
                        <button class="btn btn-sm btn-primary" id="btn-filter">
                            <i class="bx bx-search me-1"></i>Lọc
                        </button>
                        <button class="btn btn-sm btn-outline-secondary" id="btn-reset" title="Reset bộ lọc">
                            <i class="bx bx-reset"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ─── Table card ──────────────────────────────────────────────────────── -->
    <div class="card">
        <div class="card-header d-flex align-items-center py-2">
            <h6 class="mb-0 fw-semibold">
                <i class="bx bx-table me-2 text-primary"></i>Danh sách log nhập HTSoft
            </h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-striped table-bordered table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="text-center px-3" style="width:55px">#</th>
                            <th style="min-width:120px">Shop</th>
                            <th style="width:130px">Thời gian</th>
                            <th style="width:130px">Mã đơn</th>
                            <th style="width:140px">Mã HĐ HTSoft</th>
                            <th class="text-center" style="width:95px">Lệch giá</th>
                            <th class="text-center" style="width:95px">SKU thiếu</th>
                            <th class="text-center" style="width:115px">Trạng thái</th>
                            <th class="text-center" style="width:95px">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody id="tgshm-tbody">
                        <tr>
                            <td colspan="9" class="text-center py-4 text-muted">
                                <div class="spinner-border spinner-border-sm me-2" role="status"></div>Đang tải…
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer py-2 bg-transparent">
            <div id="tgshm-pagination" class="d-flex justify-content-center flex-wrap gap-1"></div>
        </div>
    </div>

</div><!-- /#tgs-htsoft-monitor-app -->

<!-- ─── Detail / Resolve Modal ─────────────────────────────────────────────── -->
<div class="modal fade" id="tgshm-modal" tabindex="-1" aria-labelledby="tgshm-modal-label" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-semibold" id="tgshm-modal-label">
                    <i class="bx bx-detail me-2 text-primary"></i>Chi tiết log import
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body" id="tgshm-modal-body">
                <div class="text-center py-4 text-muted">
                    <div class="spinner-border spinner-border-sm me-2" role="status"></div>Đang tải…
                </div>
            </div>

            <div class="modal-footer d-block" id="tgshm-resolve-section" style="display:none!important">
                <h6 class="fw-semibold mb-3">
                    <i class="bx bx-edit-alt me-1 text-warning"></i>Cập nhật xử lý
                </h6>
                <input type="hidden" id="resolve-log-id">
                <input type="hidden" id="resolve-blog-id">
                <div class="row g-2 mb-3">
                    <div class="col-md-3">
                        <label class="form-label small fw-semibold mb-1">Trạng thái</label>
                        <select id="resolve-status" class="form-select form-select-sm">
                            <option value="pending">⏳ Chờ xử lý</option>
                            <option value="in_progress">🔄 Đang xử lý</option>
                            <option value="resolved">✅ Đã giải quyết</option>
                            <option value="ignored">🚫 Bỏ qua</option>
                        </select>
                    </div>
                    <div class="col-md-9">
                        <label class="form-label small fw-semibold mb-1">Ghi chú xử lý</label>
                        <textarea id="resolve-note" class="form-control form-control-sm" rows="2"
                            placeholder="Mô tả cách xử lý…"></textarea>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-3">
                    <button class="btn btn-primary btn-sm" id="btn-resolve-save">
                        <i class="bx bx-save me-1"></i>Lưu thay đổi
                    </button>
                    <span id="resolve-msg" class="small"></span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Config + script -->
<script>
window.htSoftMonitorConfig = {
    ajaxUrl: '<?php echo esc_js($_ajax_url); ?>',
    nonce:   '<?php echo esc_js($_nonce); ?>',
    blogId:  <?php echo intval($_blog_id); ?>,
    blogs:   <?php echo wp_json_encode($_blogs); ?>
};
</script>
<script src="<?php echo esc_url(TGS_HTSOFT_MONITOR_URL . 'assets/admin.js'); ?>?v=<?php echo esc_attr(TGS_HTSOFT_MONITOR_VERSION); ?>"></script>
