# Luồng sản phẩm global trong TGS HTSoft Monitor

Tài liệu này mô tả cách `tgs_htsoft_monitor` xử lý thông tin sản phẩm sau khi hệ thống chuyển catalog về global product.

## Nguyên tắc

- Plugin này không đọc catalog từ bảng `local_product_name`.
- POS là nơi map SKU HTSoft sang sản phẩm TGS. Việc map hiện phải dùng global product theo tài liệu `wp-content/plugins/tgs_shop_management/docs/global-product-api.md`.
- Monitor chỉ nhận payload log từ POS và lưu lại snapshot phục vụ kiểm tra.
- Khi monitor cần bổ sung tên, SKU, giá DB hoặc metadata sản phẩm cho payload log, phải dùng `TGS_Global_Product_Source`.
- Các field trong log như `sku`, `mapped_sku`, `db_price`, `selected_items`, `price_diff_items` là snapshot tại thời điểm bán/import, không phải dữ liệu catalog local.

## Adapter nội bộ

File:

```text
includes/class-htsoft-monitor-global-products.php
```

Class:

```php
TGS_HTSoft_Monitor_Global_Products
```

Hàm chính:

- `ensure_source()`: nạp `TGS_Global_Product_Source` từ `tgs_shop_management`.
- `products_by_skus(array $skus)`: lấy map sản phẩm global theo `global_product_sku`.
- `normalize_log_payload(array $payload)`: enrich các mảng `price_diff_items`, `selected_items`, `map_result` theo global product.
- `extract_item_sku(array $item)`: lấy SKU ưu tiên theo `global_product_sku`, `mapped_sku`, `sku`, `htsoft_sku`.

## Luồng ghi log POS

File:

```text
tgs-htsoft-monitor.php
```

Hook:

```php
do_action('tgs_pos_order_committed', $sale_ledger_id, $sale_code, $payload, $extra)
```

Quy trình:

- Đọc payload HTSoft từ `$extra['htsoft']` hoặc `$_POST`.
- Decode các field JSON:
  - `htsoft_map_result`
  - `htsoft_price_diff_items`
  - `htsoft_unmatched_skus`
  - `htsoft_selected_items`
- Gọi `TGS_HTSoft_Monitor_Global_Products::normalize_log_payload()` để bổ sung thông tin global cho các item có SKU.
- Ghi snapshot vào bảng `{prefix}local_htsoft_import_log`.

## Luồng xem chi tiết log

File:

```text
includes/class-htsoft-monitor-ajax.php
```

Endpoint:

```text
tgs_htsoft_monitor_get_detail
```

Quy trình:

- Đọc log theo shop/blog.
- Decode JSON fields.
- Chạy lại `normalize_log_payload()` để enrich log cũ bằng global product nếu trước đó chưa có metadata global.
- Trả dữ liệu cho dashboard.

## Khi phát triển tính năng mới

- Không thêm query/join tới `local_product_name`, `local_product_cat` hoặc bảng catalog local khác.
- Nếu cần search sản phẩm: gọi REST API `/wp-json/tgs-shop/v1/products` hoặc `TGS_Global_Product_Source::query_products()`.
- Nếu cần bổ sung tên sản phẩm từ SKU trong log: dùng `TGS_HTSoft_Monitor_Global_Products::products_by_skus()`.
- Nếu cần so sánh giá, dùng giá snapshot trong log để giữ đúng thời điểm bán. Chỉ dùng global price khi payload cũ thiếu giá DB.
