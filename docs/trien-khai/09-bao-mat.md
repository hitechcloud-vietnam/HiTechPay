# 9. Bảo mật & hardening

## 9.1 Mô hình mối đe doạ (tóm tắt)

| Tài sản | Rủi ro | Biện pháp |
|---|---|---|
| Master encryption key | Lộ → giải mã khoá cổng thanh toán, dữ liệu nhạy cảm | Lưu ngoài máy chủ (Vault/KMS/password manager), quyền đọc tối thiểu, `chmod 600 deploy/.env` |
| Khoá cổng thanh toán (MoMo, VNPAY…) | Lộ → tạo/hoàn giao dịch giả | Lưu mã hoá trong DB (mặc định), chỉ nhập qua dashboard/API, xoay khoá định kỳ |
| Admin API key | Toàn quyền tạo merchant, API key | Chuỗi ngẫu nhiên 64 hex, không dùng trong ứng dụng merchant, chỉ gọi từ mạng quản trị |
| Webhook endpoint | Giả mạo kết quả thanh toán | Mọi connector VN xác thực chữ ký/token; không tắt xác thực |
| Dashboard | Chiếm tài khoản | TOTP 2FA, mật khẩu mạnh, xoá user mẫu, giới hạn IP nếu có thể |
| PostgreSQL / Redis | Truy cập trái phép | Không publish ra Internet, mật khẩu mạnh, mạng riêng |

## 9.2 Checklist máy chủ

- [ ] Hệ điều hành cập nhật tự động bản vá bảo mật: `sudo apt-get install unattended-upgrades`.
- [ ] SSH: chỉ khoá, tắt mật khẩu và đăng nhập root (`PasswordAuthentication no`, `PermitRootLogin no`), fail2ban.
- [ ] Firewall chỉ mở 22 (giới hạn IP quản trị), 80, 443.
- [ ] Docker publish cổng bỏ qua ufw → **luôn dùng** `deploy/docker-compose.prod.yml` (cổng 127.0.0.1, không publish DB).
- [ ] `deploy/.env` quyền 600, chủ sở hữu root; sao lưu mã hoá ở nơi khác.
- [ ] Đồng hồ hệ thống đồng bộ NTP (chữ ký nhiều cổng VN phụ thuộc thời gian).
- [ ] Log xoay vòng (prod override: 5 × 50 MB mỗi container) và gửi ra hệ thống log tập trung.

## 9.3 Ứng dụng

- [ ] Đổi toàn bộ secret mặc định (`install-ubuntu.sh` tự sinh). Kiểm tra: `grep -E "test_admin|change-me|db_pass" deploy/.env` không ra kết quả.
- [ ] Xoá/đổi mật khẩu user mẫu `demo@hyperswitch.com` nếu đã chạy profile `bootstrap`.
- [ ] Bật TOTP cho mọi tài khoản dashboard có quyền admin.
- [ ] API key merchant: tạo riêng cho từng hệ thống, đặt hạn (`expiration`), thu hồi khi không dùng.
- [ ] Webhook merchant: luôn xác thực `X-Webhook-Signature-512`, xử lý idempotent.
- [ ] `HTP_API_PUBLIC_URL` là HTTPS (các cổng VN từ chối/không gọi về URL HTTP ở production).
- [ ] CORS của checkout SDK: thu hẹp `Access-Control-Allow-Origin` trong nginx nếu chỉ phục vụ một số tên miền.

## 9.4 TLS

- Let's Encrypt qua certbot (tự gia hạn — kiểm tra `systemctl list-timers | grep certbot`).
- Chỉ TLS 1.2+; HSTS cho `api.` và `dashboard.` sau khi ổn định:
  `add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;`
- Kiểm tra định kỳ: `https://www.ssllabs.com/ssltest/` đạt A.

## 9.5 Dữ liệu thẻ & PCI DSS

- Các phương thức VN mặc định (VietQR, ví, redirect ngân hàng) **không** để dữ liệu thẻ đi qua máy chủ HiTechPay.
- Nếu bật thanh toán thẻ trực tiếp / lưu thẻ: triển khai `hyperswitch-card-vault` + `hyperswitch-encryption-service`,
  phân vùng mạng riêng, đánh giá phạm vi PCI DSS với đơn vị QSA. Xem [07-checklist-go-live.md](07-checklist-go-live.md).

## 9.6 Quy trình khi nghi ngờ lộ khoá

1. Thu hồi khoá tại cổng thanh toán bị ảnh hưởng, cập nhật connector bằng khoá mới.
2. Đổi admin API key, JWT secret (khởi động lại router); thu hồi API key merchant liên quan.
3. Rà soát giao dịch/hoàn tiền bất thường trong khoảng thời gian nghi ngờ (dashboard → Payments/Refunds, lọc theo thời gian).
4. Nếu master key bị lộ: coi toàn bộ khoá connector đã lộ — xoay tất cả; lên kế hoạch migrate sang key mới
   (encryption service / key manager), không chỉ thay giá trị biến môi trường.
5. Ghi nhận sự cố, thông báo merchant/cơ quan theo quy định bảo vệ dữ liệu.
