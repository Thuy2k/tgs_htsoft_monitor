# TGS HTSoft Monitor

Plugin theo dõi, cảnh báo và xử lý các vấn đề phát sinh khi nhân viên **import hóa đơn HTSoft vào POS TGS**.

---

## Bối cảnh vấn đề

Nhân viên hiện phải thực hiện **2 bước tách biệt**:

1. **In bill trên HTSoft** → phần mềm quản lý bán hàng cũ của shop
2. **Scan ảnh bill → import vào POS TGS** → phần mềm mới của công ty

Hai hệ thống dùng bảng giá khác nhau nên **giá thường bị lệch**. Ngoài ra có thể xảy ra:
- SKU HTSoft không map được sang sản phẩm TGS
- Nhân viên bỏ qua bước chỉnh sửa trước khi confirm

Plugin này ghi lại toàn bộ sự kiện import, phân loại cảnh báo, và cho phép admin/kế toán theo dõi + đánh dấu đã xử lý.

---

## Kiến trúc tổng thể

```
[Nhân viên POS]
      │
      ▼
pos-htsoft-import.js          ← Scan ảnh → AI → Map SKU
      │ upload ảnh bill ngay
      ▼
tgs_htsoft_monitor_upload_image  (AJAX – mở cho logged-in user)
      │ → lưu vào wp-content/uploads/htsoft-invoices/{blog_id}/{date}/
      │ → trả về URL, JS lưu vào htSoftInvoiceImages[]
      │
      ▼
pos-order.js  saveOrderToServer()
      │ append thêm:
      │   htsoft_invoice_images  (JSON array URL)
      │   htsoft_price_diff_items
      │   htsoft_unmatched_skus
      │   htsoft_selected_items
      │   htsoft_map_result
      │   htsoft_invoice_no
      ▼
class-tgs-pos-ajax-order.php  save_order()
      │ tạo đơn hàng bình thường
      │ do_action('tgs_pos_order_committed', $sale_id, $sale_code, ...)
      ▼
tgs-htsoft-monitor.php  (hook tgs_pos_order_committed)
      │ kiểm tra is_htsoft_import=1
      │ đọc các POST field htsoft_*
      │ gọi TGS_HTSoft_Monitor_DB::insert()
      ▼
wp_{blog_id}_local_htsoft_import_log   ← bảng per-shop trong DB

[Admin TGS]
      │
      ▼
wp-admin → Quản lý Shop → ⚠ HTSoft Monitor
      │ (submenu dưới tgs_shop_management)
      ▼
admin-dashboard.php  +  admin.js
      │ gọi AJAX: tgs_htsoft_monitor_get_logs / get_detail / resolve / soft_delete
      ▼
class-htsoft-monitor-ajax.php  +  class-htsoft-monitor-db.php
```

---

## Cấu trúc file

```
tgs_htsoft_monitor/
│
├── tgs-htsoft-monitor.php              Plugin entry point
│   ├── define constants                TGS_HTSOFT_MONITOR_DIR / URL / VERSION
│   ├── require_once includes/          Load 3 class files
│   ├── plugins_loaded hook             Boot Admin + Ajax
│   └── tgs_pos_order_committed hook    Ghi log khi đơn POS commit
│
├── includes/
│   ├── class-htsoft-monitor-db.php     CRUD wrapper cho bảng DB
│   ├── class-htsoft-monitor-ajax.php   Xử lý AJAX request
│   └── class-htsoft-monitor-admin.php  Đăng ký menu + enqueue assets
│
├── templates/
│   └── admin-dashboard.php             HTML của trang admin (SPA jQuery)
│
└── assets/
    ├── admin.css                        Styles cho dashboard
    └── admin.js                         Logic dashboard: filter, paginate, modal, resolve
```

---

## Bảng DB: `{prefix}local_htsoft_import_log`

Bảng **per-shop** (mỗi blog trong multisite có bảng riêng).  
Được tạo bởi `tgs_shop_management` (class-tgs-database.php) khi activate.

| Cột | Kiểu | Mô tả |
|-----|------|-------|
| `id` | BIGINT UNSIGNED PK | Auto increment |
| `blog_id` | BIGINT UNSIGNED | ID shop (get_current_blog_id()) |
| `sale_id` | BIGINT UNSIGNED | local_ledger_id của đơn hàng |
| `sale_code` | VARCHAR(100) | Mã đơn hàng (VD: HD001_ABC123) |
| `htsoft_invoice_no` | VARCHAR(100) | Mã hóa đơn trên HTSoft (nếu AI đọc được) |
| `invoice_images` | LONGTEXT | JSON array URL ảnh bill đã upload |
| `raw_ai_result` | LONGTEXT | JSON kết quả AI thô (hiện để null) |
| `map_result` | LONGTEXT | JSON array toàn bộ htSoftMapResult |
| `price_diff_count` | SMALLINT | Số SP bị lệch giá |
| `price_diff_items` | LONGTEXT | JSON `[{sku, name, invoice_price, db_price}]` |
| `unmatched_count` | SMALLINT | Số SKU không tìm thấy |
| `unmatched_skus` | LONGTEXT | JSON `["SKU1", "SKU2"]` |
| `selected_items` | LONGTEXT | JSON các SP đã được chọn import |
| `resolution_status` | ENUM | `pending / in_progress / resolved / ignored` |
| `resolution_note` | TEXT | Ghi chú cách xử lý |
| `resolved_by` | INTEGER | WP user_id người giải quyết |
| `resolved_at` | DATETIME | Thời điểm giải quyết |
| `user_id` | INTEGER | Nhân viên tạo đơn |
| `is_deleted` | TINYINT(1) | Soft delete flag |
| `deleted_at / created_at / updated_at` | DATETIME | Timestamps |

**Index:** `idx_blog_id`, `idx_sale_id`, `idx_sale_code`, `idx_resolution_status`, `idx_created_at`

---

## Luồng dữ liệu chi tiết

### 1. Nhân viên scan ảnh bill

```
openHtSoftImportModal()
  └── _htSoftReset()                   reset htSoftInvoiceImages = []

[User chọn/paste ảnh]
  └── _htSoftLoadFile(file)            set htSoftRawFile, htSoftImagePreview

[User bấm Phân tích]
  └── processHtSoftImage()
        ├── POST tgs_ai_process_pos_file (upload file, AI trả về items + customer)
        ├── this.htSoftInvoiceNo = rawData.invoice_no || ''
        ├── _htSoftUploadInvoiceImage(this.htSoftRawFile)   ← chạy song song
        │     POST tgs_htsoft_monitor_upload_image
        │     → lưu vào uploads/htsoft-invoices/{blog_id}/{date}/{filename}
        │     → push {url, name} vào htSoftInvoiceImages[]
        └── mapHtSoftSkus(rawData.items)

[User chỉnh sửa SL/giá, chọn checkbox, bấm "+ Ảnh bill" thêm ảnh thủ công]
  └── htSoftHandleExtraImages(e)        upload từng file, push URL vào htSoftInvoiceImages[]

[User bấm Xác nhận import]
  └── fillHtSoftItemsToCart()           fill cart, close modal
```

### 2. Nhân viên thanh toán đơn

```
saveOrderToServer()
  ├── formData ... (order data bình thường)
  ├── formData.append("is_htsoft_import", "1")
  ├── formData.append("htsoft_invoice_images",  JSON.stringify(htSoftInvoiceImages.map(img => img.url)))
  ├── formData.append("htsoft_price_diff_items", JSON)   ← items có invoice_price ≠ db_price
  ├── formData.append("htsoft_unmatched_skus",   JSON)   ← items !found
  ├── formData.append("htsoft_selected_items",   JSON)   ← items found && selected
  ├── formData.append("htsoft_map_result",       JSON)   ← toàn bộ map result
  └── formData.append("htsoft_invoice_no",       string)

→ POST tgs_pos_save_order
→ tgs_shop_create_sale_from_pos hook → tạo đơn TGS
→ do_action('tgs_pos_order_committed', $sale_id, $sale_code, $payload, $extra)
  └── [tgs_htsoft_monitor] hook callback
        ├── kiểm tra $_POST['is_htsoft_import']
        ├── decode các POST field htsoft_*
        └── TGS_HTSoft_Monitor_DB::insert([...])   → ghi log vào DB
```

### 3. Admin xem dashboard

```
wp-admin → Quản lý Shop → ⚠ HTSoft Monitor

admin.js init()
  ├── loadStats()   → 4 AJAX calls song song để fill stats bar
  └── loadLogs()    → AJAX tgs_htsoft_monitor_get_logs (filter + paginate)
        POST: blog_id?, resolution_status?, has_price_diff?, has_unmatched?, date_from?, date_to?
        → class-htsoft-monitor-ajax.php::get_logs()
              switch_to_blog($blog_id) nếu cần
              TGS_HTSoft_Monitor_DB::query(...)
              restore_current_blog()
              return {items, total}

[Admin click "Chi tiết"]
  └── openDetail(id, blog_id)
        POST tgs_htsoft_monitor_get_detail
        → decode JSON fields invoice_images, price_diff_items, unmatched_skus, selected_items
        → render modal: ảnh bill, bảng lệch giá, SKU tag, item đã import

[Admin cập nhật xử lý]
  └── #btn-resolve-save click
        POST tgs_htsoft_monitor_resolve
          id, blog_id, status, note
        → TGS_HTSoft_Monitor_DB::update_resolution()
        → reload table + stats
```

---

## AJAX Endpoints

| Action | Method | Auth | Mô tả |
|--------|--------|------|-------|
| `tgs_htsoft_monitor_get_logs` | POST | manage_options | Lấy danh sách có phân trang + filter |
| `tgs_htsoft_monitor_get_detail` | POST | manage_options | Chi tiết 1 log (decode JSON fields) |
| `tgs_htsoft_monitor_resolve` | POST | manage_options | Cập nhật resolution_status + ghi chú |
| `tgs_htsoft_monitor_soft_delete` | POST | manage_options | Soft delete bản ghi |
| `tgs_htsoft_monitor_upload_image` | POST | is_user_logged_in | Upload ảnh bill từ POS hoặc admin |

**Nonce:**
- Endpoints admin dùng: `tgs_htsoft_monitor_nonce` (tạo bởi `wp_create_nonce()` trong `enqueue_assets()`)
- `upload_image` chấp nhận cả `tmd_pos_nonce` (POS frontend) và `tgs_htsoft_monitor_nonce` (admin)

---

## Upload ảnh

**Path server:** `wp-content/uploads/htsoft-invoices/{blog_id}/{YYYY-MM-DD}/{filename}`

**Từ POS (tự động):** Sau khi AI scan thành công, ảnh được upload ngầm, không block luồng chính.  
**Từ POS (thủ công):** Nút "+ Ảnh bill" trong khu vực HTSoft controls (cả mobile lẫn desktop).  
**Từ Admin:** (Chưa có — có thể thêm vào modal chi tiết sau).

File được validate kiểu ảnh (`image/jpeg`, `image/png`, `image/gif`, `image/webp`) và được đặt tên unique bằng `wp_unique_filename()`.

---

## Multisite

- Bảng log là **per-shop** (per-blog prefix) — mỗi shop có bảng riêng
- AJAX admin dùng `switch_to_blog($blog_id)` + `restore_current_blog()` để truy vấn đúng bảng
- Dashboard mặc định hiện log của site hiện tại (blog 1); admin chọn shop từ dropdown để xem shop khác
- Upload ảnh dùng `$blog_id` từ POST để tổ chức thư mục, không cần switch blog

---

## Kích hoạt plugin

1. Deactivate → Activate **tgs_shop_management** để chạy `dbDelta()` tạo bảng `local_htsoft_import_log`  
   *(hoặc bump `DB_VERSION` → plugin tự migrate khi load)*
2. Activate **tgs_htsoft_monitor**
3. Vào **WP Admin → Quản lý Shop → ⚠ HTSoft Monitor**

---

## Những gì chưa làm (backlog)

- [ ] Upload ảnh từ phía admin (thêm vào sau khi tạo đơn)
- [ ] Thống kê cross-shop (cần UNION query qua tất cả blog hoặc global table)
- [ ] Email/notification khi có log mới với `price_diff_count > 0`
- [ ] Link trực tiếp đến trang chi tiết đơn hàng trong tgs_shop_management
- [ ] Export CSV danh sách log
