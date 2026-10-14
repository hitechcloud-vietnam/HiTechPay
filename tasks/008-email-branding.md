# 008 — Branding e-mail & tài sản tĩnh

Trạng thái: Chưa làm
Ưu tiên: P1

## Bối cảnh
- E-mail (mời người dùng, đặt lại mật khẩu) lấy tên/logo từ cấu hình router `theme.email_config`
  (`entity_name`, `entity_logo_url`, màu). Mặc định upstream vẫn là Hyperswitch và logo PNG trên app.hyperswitch.io.
- Helm values dùng placeholder `https://hitechpay.vn/assets/logo.png`.
- Dashboard còn link Slack cộng đồng Hyperswitch và footer "An open-source initiative by Juspay" (ẩn khi bật feature `branding`).

## Các bước
1. Xuất logo PNG (≥ 400px) từ `branding/assets/logo.svg`, host tại URL tuyệt đối (CDN của bạn).
2. Đặt `theme.email_config` trong `upstream/hyperswitch/config/*.toml` (qua patch) và trong Helm values / biến
   `ROUTER__THEME__EMAIL_CONFIG__*` cho compose.
3. Bật `branding=true` trong `deploy/config/dashboard.toml` nếu muốn ẩn footer Juspay; thay link Slack bằng kênh hỗ trợ của bạn
   (sửa `tools/whitelabel.py` để idempotent).
4. Gửi thử e-mail qua profile `full_setup` (mailhog) để kiểm tra.

## Tiêu chí hoàn thành
- E-mail mời/đặt lại mật khẩu hiển thị tên + logo HiTechPay; không còn tài sản trỏ về hyperswitch.io trong luồng người dùng.
