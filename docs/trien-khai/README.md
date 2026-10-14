# Hướng dẫn triển khai HiTechPay — mục lục

Bộ tài liệu này hướng dẫn từng bước đưa HiTechPay Platform từ mã nguồn lên môi trường chạy thật,
cấu hình các cổng thanh toán Việt Nam, tích hợp cho merchant và vận hành hằng ngày.

| # | Tài liệu | Dành cho | Nội dung |
|---|---|---|---|
| 1 | [Chuẩn bị](01-chuan-bi.md) | DevOps, quản lý dự án | Phần cứng, tên miền, tài khoản cổng thanh toán, sinh secret |
| 2 | [Một máy chủ — Docker Compose](02-docker-compose.md) | DevOps | Cài đặt từ A–Z trên Ubuntu, TLS, systemd, sao lưu |
| 3 | [Kubernetes — Helm](03-kubernetes-helm.md) | DevOps / SRE | Cluster, image GHCR, secret, ingress, nâng cấp |
| 4 | [Cấu hình cổng thanh toán VN](04-cong-thanh-toan.md) | Vận hành, kỹ thuật | MoMo, VNPAY, ZaloPay, Viettel Money, payOS, SePay, Pay2S: đăng ký, khoá, webhook, kiểm thử |
| 5 | [Tích hợp cho merchant](05-tich-hop-merchant.md) | Lập trình viên merchant | API key, tạo thanh toán, SDK web/mobile, webhook, plugin |
| 6 | [Vận hành](06-van-hanh.md) | SRE | Giám sát, log, sao lưu/khôi phục, xoay khoá, mở rộng, nâng cấp, xử lý sự cố |
| 7 | [Checklist go-live](07-checklist-go-live.md) | Tất cả | Bảo mật, pháp lý, kiểm thử trước khi nhận tiền thật |

## Chọn mô hình triển khai

| Tiêu chí | Docker Compose (1 máy) | Kubernetes (Helm) |
|---|---|---|
| Phù hợp | Sandbox, demo, merchant nhỏ (< ~50 giao dịch/giây) | Production, nhiều merchant, HA |
| Thời gian dựng | 1–2 giờ (build lần đầu 30–60 phút) | 0,5–1 ngày (khi đã có cluster) |
| Tính sẵn sàng | 1 điểm lỗi | Nhiều replica, tự khôi phục |
| CSDL | PostgreSQL trong container | Khuyến nghị PostgreSQL/Redis managed |

## Kiến trúc tóm tắt

```
                        Internet (HTTPS)
                              │
        ┌─────────────────────┼──────────────────────────┐
        │  api.<domain>       │ dashboard.<domain>        │ checkout.<domain>
        ▼                     ▼                           ▼
   ┌─────────┐          ┌───────────┐               ┌──────────────┐
   │ router  │◄─────────│ dashboard │               │ checkout-sdk │ (HyperLoader.js)
   │ (API)   │          └───────────┘               └──────────────┘
   └────┬────┘
        │  PostgreSQL · Redis · Superposition · (scheduler consumer/producer, drainer)
        │
        ▼  HTTPS ra ngoài
   MoMo · VNPAY · ZaloPay · Viettel Money · payOS · SePay · Pay2S · OnePay … + 150 PSP quốc tế
        ▲
        └── webhook/IPN về  https://api.<domain>/webhooks/<merchant_id>/<merchant_connector_id>
```

Thuật ngữ dùng trong tài liệu:
- **Merchant**: doanh nghiệp bán hàng dùng HiTechPay. **Profile**: cấu hình thanh toán của merchant (mỗi merchant có ≥ 1).
- **Connector**: một cổng thanh toán (MoMo, VNPAY…) đã gắn khoá của merchant — `merchant_connector_id` dạng `mca_…`.
- **Router**: dịch vụ API trung tâm. **Dashboard**: giao diện quản trị. **Checkout SDK**: thư viện JS hiển thị form thanh toán.
