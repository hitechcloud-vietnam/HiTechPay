# 005 — Viettel Money: đối chiếu đặc tả + Partner API v2

Trạng thái: Chưa làm (cần tài liệu tích hợp Viettel cấp cho đối tác)
Ưu tiên: P1

## Bối cảnh
Connector `viettelmoney` hiện theo cổng ViettelPay "version 2.0" (checksum HMAC-SHA1 base64), PSync xử lý cục bộ từ IPN /
tham số return, không có hoàn tiền API. Đặc tả Viettel không công khai nên **tên trường, URL sandbox/production, định dạng
phản hồi IPN cần xác nhận**. Code: `upstream/hyperswitch/crates/hyperswitch_connectors/src/connectors/viettelmoney*`.

## Các bước
1. Nhận tài liệu tích hợp mới nhất (Partner API, có thể dùng RSA) từ Viettel.
2. Đối chiếu từng chuỗi checksum trong `transformers.rs` (`build_payment_url`, `checksum_payload`, `ViettelmoneyIpnResponse`).
3. Nếu có API truy vấn/hoàn tiền: bỏ `should_build_psync_response_locally`, cài PSync/Refund/RSync thật.
4. Cập nhật base URL trong `tools/register_connectors.py` (chạy lại script), `docs/connectors-vn.md`.

## Tiêu chí hoàn thành
- Giao dịch sandbox thành công end-to-end (task 004), unit test với vector thật.
