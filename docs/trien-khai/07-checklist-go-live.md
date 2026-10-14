# 7. Checklist go-live

Đánh dấu toàn bộ trước khi nhận tiền thật.

## Pháp lý & hợp đồng
- [ ] Đã xác định mô hình pháp lý: HiTechPay là **phần mềm/nền tảng kỹ thuật** cho merchant tự ký hợp đồng với
      từng cổng, hay HiTechPay **thu hộ/chi hộ** (trung gian thanh toán). Trường hợp sau cần **giấy phép của Ngân hàng
      Nhà nước** theo quy định về dịch vụ trung gian thanh toán (Nghị định 52/2024/NĐ-CP) — tham vấn luật sư.
- [ ] Hợp đồng với từng cổng (MoMo, VNPAY, ZaloPay, Viettel Money, payOS, SePay, Pay2S) đã ký; khoá production đã cấp.
- [ ] Điều khoản sử dụng, chính sách bảo mật, chính sách hoàn tiền hiển thị cho merchant/khách hàng.
- [ ] Nội dung tuân thủ trong giao diện (câu chữ PCI DSS, e-mail Apple Pay) đã được pháp chế duyệt.
- [ ] Tuân thủ bảo vệ dữ liệu cá nhân (Nghị định 13/2023/NĐ-CP): đánh giá tác động, nơi lưu trữ dữ liệu.

## Bảo mật
- [ ] Đã đổi toàn bộ secret mặc định (`test_admin`, `secret`, master key mẫu, `db_pass`).
- [ ] Đã xoá hoặc đổi mật khẩu user mẫu `demo@hyperswitch.com`.
- [ ] Chỉ mở 80/443 ra Internet; DB/Redis/cổng ứng dụng không public; SSH giới hạn IP + key.
- [ ] TLS hợp lệ cho cả 3 tên miền, tự gia hạn.
- [ ] Sao lưu `deploy/.env`/Secret K8s (master key) ở 2 nơi an toàn, có người chịu trách nhiệm.
- [ ] Bật 2FA (TOTP) cho tài khoản dashboard quản trị.
- [ ] Nếu lưu thẻ: bật card vault, phạm vi PCI DSS đã đánh giá; nếu không, chỉ dùng phương thức redirect/QR (không chạm dữ liệu thẻ).
- [ ] Rà soát log không chứa khoá/thẻ (masking mặc định).

## Cấu hình
- [ ] `HTP_RUN_ENV=production` / `run_env: production`; base URL production cho MoMo, VNPAY, ZaloPay, Viettel Money.
- [ ] Connector production: Test mode **tắt**, khoá production, phương thức & giới hạn số tiền đúng.
- [ ] Webhook/IPN URL production đã khai báo ở VNPAY, payOS, SePay, Pay2S, Viettel Money.
- [ ] IP máy chủ đã đăng ký với cổng yêu cầu whitelist.
- [ ] `dashboard.toml` trỏ `api_url`/`sdk_url` production; logo e-mail là URL PNG tuyệt đối.
- [ ] Scheduler (consumer + producer) đang chạy.

## Kiểm thử (môi trường production, số tiền nhỏ)
- [ ] MoMo ví: thanh toán thành công → webhook → `succeeded` → hoàn tiền thành công.
- [ ] MoMo ATM, VNPAY-QR, VNPAY ngân hàng nội địa, ZaloPay, Viettel Money, payOS: thanh toán + trạng thái.
- [ ] SePay/Pay2S: chuyển khoản đúng nội dung → `succeeded`; chuyển thiếu → `partially_captured`.
- [ ] Khách huỷ giữa chừng → trạng thái thất bại/hết hạn đúng.
- [ ] Webhook tới hệ thống merchant được xác thực chữ ký và xử lý idempotent.
- [ ] Plugin WooCommerce/WHMCS (nếu dùng): một đơn hàng trọn vẹn.

## Vận hành
- [ ] Giám sát + cảnh báo (health, tỷ lệ thành công, TLS, disk) gửi tới kênh trực.
- [ ] Sao lưu tự động đã chạy và **đã thử khôi phục**.
- [ ] Runbook xử lý sự cố ([06-van-hanh.md](06-van-hanh.md#67-xử-lý-sự-cố)) đã phổ biến cho đội trực.
- [ ] Đầu mối kỹ thuật của từng cổng thanh toán đã lưu.
