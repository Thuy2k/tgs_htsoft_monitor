/**
 * TGS HTSoft Monitor — Admin JS  (Bootstrap 5 / Sneat edition)
 * Config is injected by the template as window.htSoftMonitorConfig
 */
jQuery(function ($) {
    'use strict';

    var cfg     = window.htSoftMonitorConfig || window.tgsHtMonitor || {};
    var ajaxUrl = cfg.ajaxUrl || '';
    var nonce   = cfg.nonce   || '';

    var currentPage = 1;
    var totalPages  = 1;
    var perPage     = 25;

    // Bootstrap 5 modal instance
    var bsModal = null;
    var $modalEl = document.getElementById('tgshm-modal');
    if ($modalEl && typeof bootstrap !== 'undefined') {
        bsModal = new bootstrap.Modal($modalEl);
    }

    // ─── Populate shop dropdown from config ──────────────────────────────────

    function tryPopulateBlogs(data) {
        if (!data || !data.blogs) return;
        var $sel = $('#filter-blog');
        if ($sel.find('option').length > 1) return; // already populated
        data.blogs.forEach(function (b) {
            $sel.append($('<option>').val(b.id).text(b.name + ' (#' + b.id + ')'));
        });
    }

    // Populate immediately from page config (no need to wait for first AJAX call)
    tryPopulateBlogs(cfg);

    // ─── Status badge helper ─────────────────────────────────────────────────

    function statusBadge(s) {
        var map = {
            pending:     '<span class="badge bg-label-warning">Chờ xử lý</span>',
            in_progress: '<span class="badge bg-label-info">Đang xử lý</span>',
            resolved:    '<span class="badge bg-label-success">Đã xử lý</span>',
            ignored:     '<span class="badge bg-label-secondary">Bỏ qua</span>',
        };
        return map[s] || ('<span class="badge bg-label-secondary">' + escHtml(s) + '</span>');
    }

    // ─── Load stats ──────────────────────────────────────────────────────────

    function loadStats() {
        var statMap = { pending: '#stat-pending', resolved: '#stat-resolved' };

        Object.keys(statMap).forEach(function (s) {
            $.post(ajaxUrl, {
                action: 'tgs_htsoft_monitor_get_logs', nonce: nonce,
                resolution_status: s, per_page: 1, page: 1
            }, function (r) {
                if (r.success) $(statMap[s]).text(Number(r.data.total).toLocaleString('vi-VN'));
            });
        });

        $.post(ajaxUrl, {
            action: 'tgs_htsoft_monitor_get_logs', nonce: nonce,
            has_price_diff: 1, per_page: 1, page: 1
        }, function (r) {
            if (r.success) $('#stat-price-diff').text(Number(r.data.total).toLocaleString('vi-VN'));
        });

        $.post(ajaxUrl, {
            action: 'tgs_htsoft_monitor_get_logs', nonce: nonce,
            has_unmatched: 1, per_page: 1, page: 1
        }, function (r) {
            if (r.success) $('#stat-unmatched').text(Number(r.data.total).toLocaleString('vi-VN'));
        });
    }

    // ─── Build filter params ─────────────────────────────────────────────────

    function buildFilters() {
        return {
            action:            'tgs_htsoft_monitor_get_logs',
            nonce:             nonce,
            blog_id:           $('#filter-blog').val()                    || '',
            resolution_status: $('#filter-status').val()                  || '',
            has_price_diff:    $('#filter-price-diff').is(':checked') ? 1 : 0,
            has_unmatched:     $('#filter-unmatched').is(':checked')  ? 1 : 0,
            date_from:         $('#filter-date-from').val()               || '',
            date_to:           $('#filter-date-to').val()                 || '',
            per_page:          perPage,
            page:              currentPage,
        };
    }

    // ─── Load logs table ─────────────────────────────────────────────────────

    function loadLogs() {
        var $tbody = $('#tgshm-tbody');
        $tbody.html('<tr><td colspan="9" class="text-center py-4 text-muted">' +
            '<div class="spinner-border spinner-border-sm me-2" role="status"></div>Đang tải…</td></tr>');

        $.post(ajaxUrl, buildFilters(), function (resp) {
            if (!resp.success) {
                $tbody.html('<tr><td colspan="9" class="text-center text-danger py-3">' +
                    escHtml(resp.data && resp.data.message || 'Lỗi tải dữ liệu') + '</td></tr>');
                return;
            }

            tryPopulateBlogs(resp.data);

            var items = resp.data.items || [];
            var total = resp.data.total || 0;
            totalPages = Math.max(1, Math.ceil(total / perPage));

            if (!items.length) {
                $tbody.html('<tr><td colspan="9" class="text-center text-muted py-4">' +
                    '<i class="bx bx-info-circle me-1"></i>Không có bản ghi nào.</td></tr>');
                renderPagination();
                return;
            }

            var rows = items.map(function (item) {
                var diffCell = item.price_diff_count > 0
                    ? '<span class="badge bg-label-danger">' + item.price_diff_count + ' SP</span>'
                    : '<span class="text-muted small">—</span>';

                var missCell = item.unmatched_count > 0
                    ? '<span class="badge bg-label-warning">' + item.unmatched_count + ' SKU</span>'
                    : '<span class="text-muted small">—</span>';

                var saleLink = item.sale_code
                    ? '<a href="' + escHtml(adminUrl('admin.php?page=tgs-shop-management&view=ticket-sale-detail&id=' + item.sale_id)) +
                      '" target="_blank" class="fw-semibold text-primary">' + escHtml(item.sale_code) + '</a>'
                    : '<span class="text-muted">—</span>';

                var created = item.created_at ? item.created_at.substring(0, 16) : '—';

                return '<tr>' +
                    '<td class="text-center text-muted small px-3">' + item.id + '</td>' +
                    '<td><span class="fw-semibold small">' + escHtml(item.shop_name || ('Shop #' + item.blog_id)) + '</span></td>' +
                    '<td class="small text-nowrap">' + created + '</td>' +
                    '<td>' + saleLink + '</td>' +
                    '<td class="small font-monospace">' + escHtml(item.htsoft_invoice_no || '—') + '</td>' +
                    '<td class="text-center">' + diffCell + '</td>' +
                    '<td class="text-center">' + missCell + '</td>' +
                    '<td class="text-center">' + statusBadge(item.resolution_status) + '</td>' +
                    '<td class="text-center">' +
                        '<button class="btn btn-xs btn-icon btn-outline-primary btn-detail me-1" ' +
                            'data-id="' + item.id + '" data-blog="' + item.blog_id + '" title="Chi tiết">' +
                            '<i class="bx bx-show"></i></button>' +
                        '<button class="btn btn-xs btn-icon btn-outline-danger btn-delete" ' +
                            'data-id="' + item.id + '" data-blog="' + item.blog_id + '" title="Xoá">' +
                            '<i class="bx bx-trash"></i></button>' +
                    '</td>' +
                    '</tr>';
            });

            $tbody.html(rows.join(''));
            renderPagination();
        });
    }

    // ─── Pagination ──────────────────────────────────────────────────────────

    function renderPagination() {
        var $p = $('#tgshm-pagination').empty();
        if (totalPages <= 1) return;

        function pgBtn(html, page, disabled, active) {
            return $('<button>')
                .addClass('btn btn-sm ' + (active ? 'btn-primary' : 'btn-outline-secondary'))
                .prop('disabled', !!disabled)
                .html(html)
                .on('click', function () { currentPage = page; loadLogs(); });
        }

        $p.append(pgBtn('<i class="bx bx-chevrons-left"></i>', 1, currentPage === 1, false));
        $p.append(pgBtn('<i class="bx bx-chevron-left"></i>',  currentPage - 1, currentPage === 1, false));

        var start = Math.max(1, currentPage - 2);
        var end   = Math.min(totalPages, currentPage + 2);
        for (var i = start; i <= end; i++) {
            $p.append(pgBtn(i, i, false, i === currentPage));
        }

        $p.append(pgBtn('<i class="bx bx-chevron-right"></i>',  currentPage + 1, currentPage === totalPages, false));
        $p.append(pgBtn('<i class="bx bx-chevrons-right"></i>', totalPages,       currentPage === totalPages, false));
        $p.append($('<span>').addClass('small text-muted ms-2 align-self-center')
            .text('Trang ' + currentPage + '/' + totalPages));
    }

    // ─── Detail modal ────────────────────────────────────────────────────────

    $(document).on('click', '.btn-detail', function () {
        openDetail($(this).data('id'), $(this).data('blog'));
    });

    function openDetail(id, blogId) {
        var $body = $('#tgshm-modal-body');
        $body.html('<div class="text-center py-4 text-muted">' +
            '<div class="spinner-border spinner-border-sm me-2" role="status"></div>Đang tải…</div>');
        $('#tgshm-resolve-section').hide();
        if (bsModal) bsModal.show();

        $.post(ajaxUrl, {
            action:  'tgs_htsoft_monitor_get_detail',
            nonce:   nonce,
            id:      id,
            blog_id: blogId,
        }, function (resp) {
            if (!resp.success) {
                $body.html('<div class="alert alert-danger">' +
                    escHtml(resp.data && resp.data.message || 'Lỗi') + '</div>');
                return;
            }

            var d   = resp.data;
            var html = '';

            // ── Summary row ───────────────────────────────────────────────────
            html += '<div class="row g-3 mb-3">';

            // Left: basic info
            html += '<div class="col-md-6">';
            html += '<div class="card border-0 bg-lighter h-100">';
            html += '<div class="card-body py-3">';
            html += '<h6 class="fw-semibold text-uppercase small text-muted mb-2">Thông tin chung</h6>';
            html += '<table class="table table-sm table-borderless mb-0 small">';
            html += trow('ID log',        '#' + d.id, false);
            html += trow('Shop ID',       d.blog_id, false);
            html += trow('Thời gian',     d.created_at || '—', false);
            html += trow('Nhân viên',     d.user_id ? '#' + d.user_id : '—', false);
            html += trow('Mã đơn hàng',
                d.sale_code
                    ? '<a href="' + escHtml(adminUrl('admin.php?page=tgs-shop-management&view=ticket-sale-detail&id=' + d.sale_id)) +
                      '" target="_blank" class="fw-semibold">' + escHtml(d.sale_code) + '</a>'
                    : '—',
                true);
            html += trow('Mã HĐ HTSoft',
                '<span class="font-monospace">' + escHtml(d.htsoft_invoice_no || '—') + '</span>', true);
            html += '</table>';
            html += '</div></div>';
            html += '</div>';

            // Right: status summary
            var priceDiffCnt = d.price_diff_items ? d.price_diff_items.length : 0;
            var unmatchedCnt = d.unmatched_skus   ? d.unmatched_skus.length   : 0;
            var imageCnt     = d.invoice_images   ? d.invoice_images.length   : 0;

            html += '<div class="col-md-6">';
            html += '<div class="card border-0 bg-lighter h-100">';
            html += '<div class="card-body py-3">';
            html += '<h6 class="fw-semibold text-uppercase small text-muted mb-2">Tình trạng</h6>';
            html += '<div class="d-flex flex-column gap-2">';
            html += '<div><i class="bx bx-image text-info me-2"></i><span class="small">Ảnh hóa đơn: <strong>' + imageCnt + '</strong></span></div>';
            html += '<div><i class="bx bx-money-withdraw text-danger me-2"></i><span class="small">Lệch giá: <strong class="' + (priceDiffCnt ? 'text-danger' : '') + '">' + priceDiffCnt + ' SP</strong></span></div>';
            html += '<div><i class="bx bx-barcode text-warning me-2"></i><span class="small">SKU thiếu: <strong class="' + (unmatchedCnt ? 'text-warning' : '') + '">' + unmatchedCnt + ' SKU</strong></span></div>';
            html += '<div class="mt-1">' + statusBadge(d.resolution_status) + '</div>';
            html += '</div>';
            html += '</div></div>';
            html += '</div>';
            html += '</div>'; // end row

            // ── Invoice images ─────────────────────────────────────────────────
            if (d.invoice_images && d.invoice_images.length) {
                html += '<div class="mb-3">';
                html += '<h6 class="fw-semibold mb-2"><i class="bx bx-image-alt me-1 text-info"></i>' +
                    'Ảnh hóa đơn (' + d.invoice_images.length + ')</h6>';
                html += '<div class="d-flex flex-wrap gap-2">';
                d.invoice_images.forEach(function (img) {
                    var url = (typeof img === 'object') ? (img.url || '') : img;
                    html += '<a href="' + escHtml(url) + '" target="_blank">' +
                        '<img src="' + escHtml(url) + '" alt="bill" ' +
                        'style="height:80px;width:80px;object-fit:cover;border-radius:6px;' +
                        'border:1px solid var(--bs-border-color)"></a>';
                });
                html += '</div></div>';
            }

            // ── Price diff ─────────────────────────────────────────────────────
            if (d.price_diff_items && d.price_diff_items.length) {
                html += '<div class="mb-3">';
                html += '<h6 class="fw-semibold mb-2"><i class="bx bx-error-circle me-1 text-danger"></i>' +
                    'Sản phẩm lệch giá (' + d.price_diff_items.length + ')</h6>';
                html += '<div class="table-responsive">';
                html += '<table class="table table-sm table-bordered table-striped small mb-0">';
                html += '<thead class="table-light"><tr><th>SKU</th><th>Tên SP</th>' +
                    '<th class="text-end">Giá HTSoft</th><th class="text-end">Giá DB</th>' +
                    '<th class="text-end">Chênh lệch</th></tr></thead><tbody>';
                d.price_diff_items.forEach(function (it) {
                    var diff = parseFloat(it.invoice_price || 0) - parseFloat(it.db_price || 0);
                    var cls  = diff > 0 ? 'text-danger' : 'text-success';
                    html += '<tr>' +
                        '<td><code>' + escHtml(it.sku || '') + '</code></td>' +
                        '<td>' + escHtml(it.name || '') + '</td>' +
                        '<td class="text-end text-nowrap">' + fmtPrice(it.invoice_price) + '</td>' +
                        '<td class="text-end text-nowrap">' + fmtPrice(it.db_price) + '</td>' +
                        '<td class="text-end text-nowrap fw-semibold ' + cls + '">' +
                            (diff >= 0 ? '+' : '') + diff.toLocaleString('vi-VN') + 'đ' +
                        '</td></tr>';
                });
                html += '</tbody></table></div></div>';
            }

            // ── Unmatched SKUs ─────────────────────────────────────────────────
            if (d.unmatched_skus && d.unmatched_skus.length) {
                html += '<div class="mb-3">';
                html += '<h6 class="fw-semibold mb-2"><i class="bx bx-x-circle me-1 text-warning"></i>' +
                    'SKU không tìm thấy (' + d.unmatched_skus.length + ')</h6>';
                html += '<div class="d-flex flex-wrap gap-1">';
                d.unmatched_skus.forEach(function (sku) {
                    html += '<span class="badge bg-label-warning font-monospace">' + escHtml(sku) + '</span>';
                });
                html += '</div></div>';
            }

            // ── Selected items ─────────────────────────────────────────────────
            if (d.selected_items && d.selected_items.length) {
                html += '<div class="mb-3">';
                html += '<h6 class="fw-semibold mb-2"><i class="bx bx-check-shield me-1 text-success"></i>' +
                    'Danh sách đã import (' + d.selected_items.length + ' SP)</h6>';
                html += '<div class="table-responsive">';
                html += '<table class="table table-sm table-bordered table-striped small mb-0">';
                html += '<thead class="table-light"><tr><th>SKU</th><th>Tên SP</th>' +
                    '<th class="text-center">SL</th><th class="text-end">Đơn giá</th>' +
                    '<th class="text-center">CK%</th></tr></thead><tbody>';
                d.selected_items.forEach(function (it) {
                    html += '<tr>' +
                        '<td><code>' + escHtml(it.sku || '') + '</code></td>' +
                        '<td>' + escHtml(it.name || '') + '</td>' +
                        '<td class="text-center">' + (it.qty || '') + '</td>' +
                        '<td class="text-end text-nowrap">' + fmtPrice(it.unit_price) + '</td>' +
                        '<td class="text-center">' + (it.discount || 0) + '%</td>' +
                        '</tr>';
                });
                html += '</tbody></table></div></div>';
            }

            // ── Existing resolution note ───────────────────────────────────────
            if (d.resolution_note) {
                html += '<div class="alert alert-light border mb-0">' +
                    '<h6 class="fw-semibold mb-1"><i class="bx bx-message-detail me-1"></i>Ghi chú xử lý hiện tại</h6>' +
                    '<p class="mb-0 small">' + escHtml(d.resolution_note) + '</p></div>';
            }

            $body.html(html);

            // Prefill resolve form
            $('#tgshm-resolve-section').show();
            $('#resolve-log-id').val(d.id);
            $('#resolve-blog-id').val(d.blog_id);
            $('#resolve-status').val(d.resolution_status || 'pending');
            $('#resolve-note').val(d.resolution_note || '');
            $('#resolve-msg').text('');
        });
    }

    // ─── Resolve ─────────────────────────────────────────────────────────────

    $(document).on('click', '#btn-resolve-save', function () {
        var $msg = $('#resolve-msg');
        $msg.html('<span class="spinner-border spinner-border-sm me-1" role="status"></span>Đang lưu…')
            .removeClass('text-success text-danger').addClass('text-muted');

        $.post(ajaxUrl, {
            action:  'tgs_htsoft_monitor_resolve',
            nonce:   nonce,
            id:      $('#resolve-log-id').val(),
            blog_id: $('#resolve-blog-id').val(),
            status:  $('#resolve-status').val(),
            note:    $('#resolve-note').val(),
        }, function (resp) {
            if (resp.success) {
                $msg.html('<i class="bx bx-check me-1"></i>Đã lưu!')
                    .removeClass('text-muted text-danger').addClass('text-success');
                loadLogs();
                loadStats();
            } else {
                $msg.html('<i class="bx bx-x me-1"></i>' + escHtml(resp.data && resp.data.message || 'Lỗi'))
                    .removeClass('text-muted text-success').addClass('text-danger');
            }
        });
    });

    // ─── Delete ──────────────────────────────────────────────────────────────

    $(document).on('click', '.btn-delete', function () {
        if (!confirm('Xoá bản ghi log này?')) return;

        var id     = $(this).data('id');
        var blogId = $(this).data('blog');

        $.post(ajaxUrl, {
            action:  'tgs_htsoft_monitor_soft_delete',
            nonce:   nonce,
            id:      id,
            blog_id: blogId,
        }, function (resp) {
            if (resp.success) {
                loadLogs();
                loadStats();
            } else {
                alert(resp.data && resp.data.message || 'Xoá thất bại');
            }
        });
    });

    // ─── Filter controls ─────────────────────────────────────────────────────

    $('#btn-filter').on('click', function () { currentPage = 1; loadLogs(); });
    $('#btn-refresh').on('click', function () { currentPage = 1; loadLogs(); loadStats(); });

    $('#btn-reset').on('click', function () {
        $('#filter-blog, #filter-status').val('');
        $('#filter-price-diff, #filter-unmatched').prop('checked', false);
        $('#filter-date-from, #filter-date-to').val('');
        currentPage = 1;
        loadLogs();
    });

    // ─── Helpers ─────────────────────────────────────────────────────────────

    function escHtml(s) {
        return String(s || '')
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function fmtPrice(v) {
        return (v != null) ? Number(v).toLocaleString('vi-VN') + 'đ' : '—';
    }

    function trow(label, val, raw) {
        return '<tr><th class="text-muted fw-normal" style="width:130px">' + escHtml(label) +
            '</th><td>' + (raw ? val : escHtml(String(val || '—'))) + '</td></tr>';
    }

    function adminUrl(path) {
        // ajaxUrl = .../wp-admin/admin-ajax.php  →  strip last segment
        return ajaxUrl.replace(/admin-ajax\.php.*$/, '') + path;
    }

    // ─── Init ────────────────────────────────────────────────────────────────

    loadStats();
    loadLogs();
});
