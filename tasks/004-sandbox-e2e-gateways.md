# 004 — Kiểm thử sandbox thật từng cổng thanh toán VN

Trạng thái: Chưa làm (cần tài khoản sandbox của từng cổng)
Ưu tiên: P0

## Bối cảnh
Các connector được viết theo đặc tả công khai nhưng **chưa chạy với sandbox thật** (môi trường phát triển không truy cập
được các cổng VN). Unit test chỉ kiểm tra chữ ký/ánh xạ trạng thái.

## Các bước (lặp cho MoMo, VNPAY, ZaloPay, Viettel Money, payOS, SePay, Pay2S, OnePay, AppotaPay, 9Pay)
1. Dựng stack sandbox ([docs/trien-khai/02-docker-compose.md](../docs/trien-khai/02-docker-compose.md)) với URL public HTTPS
   (cổng thanh toán phải gọi được webhook — có thể dùng Cloudflare Tunnel/ngrok cho môi trường dev).
2. Tạo connector bằng khoá sandbox, khai báo webhook URL ([04-cong-thanh-toan.md](../docs/trien-khai/04-cong-thanh-toan.md)).
3. Ghi lại cho mỗi cổng (bảng trong `docs/test-reports/<cong>.md`):
   - Tạo thanh toán → redirect/QR hiển thị đúng.
   - Thanh toán thành công → webhook tới → `succeeded`.
   - Khách huỷ / hết hạn → trạng thái đúng.
   - Đồng bộ thủ công `GET /payments/{id}?force_sync=true`.
   - Hoàn tiền toàn phần, một phần (cổng hỗ trợ) → `RSync`.
   - Webhook giả mạo (sai chữ ký) bị từ chối.
4. Mỗi sai lệch với đặc tả → sửa connector + thêm test vector thật (đã ẩn khoá) vào unit test.
5. Điền credential sandbox vào `crates/router/tests/connectors/sample_auth.toml` (không commit khoá thật) và chạy
   `cargo test -p router --test connectors -- <tên> --ignored`.

## Tiêu chí hoàn thành
- Báo cáo test cho từng cổng; bỏ nhãn *beta* những cổng đạt toàn bộ ca.
