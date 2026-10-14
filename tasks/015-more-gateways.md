# 015 — Thêm cổng thanh toán: Napas 247, VNPT Pay, ShopeePay VN, Baokim, NganLuong

Trạng thái: Chưa làm
Ưu tiên: P2

## Cách làm (mỗi cổng)
1. Đọc tài liệu API chính thức; chọn mẫu gần nhất trong repo:
   API tạo + redirect → `momo`/`zalopay`; URL ký cục bộ → `vnpay`; chuyển khoản VietQR → `sepay`.
2. Viết connector trong `upstream/hyperswitch/crates/hyperswitch_connectors/src/connectors/<ten>` (dùng `vn_utils`),
   không thêm enum PaymentMethodType mới (dùng `viet_qr`, `local_bank_redirect`, `momo`, card redirect…).
3. Thêm vào `tools/register_connectors.py` + `tools/register_control_center.py`, chạy cả hai, build lại euclid WASM.
4. Unit test chữ ký/trạng thái; test router (ignored); docs `connectors-vn.md`.
5. `scripts/export-patches.sh` → nhánh `feat/connector-<ten>` → PR vào `main`.

## Tiêu chí hoàn thành
- Mỗi cổng: CI xanh + báo cáo sandbox (task 004).
