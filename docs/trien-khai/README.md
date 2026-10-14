# Hướng dẫn triển khai HiTechPay — mục lục

Bộ tài liệu này hướng dẫn từng bước đưa HiTechPay Platform từ mã nguồn lên môi trường chạy thật,
cấu hình các cổng thanh toán Việt Nam, tích hợp cho merchant và vận hành hằng ngày.

| # | Tài liệu | Dành cho | Nội dung |
|---|---|---|---|
| 0 | [Khởi động nhanh](00-khoi-dong-nhanh.md) | Người mới | Cài đặt 1 lệnh, kiểm tra, tạo merchant, giao dịch thử |
| 1 | [Chuẩn bị](01-chuan-bi.md) | DevOps, quản lý dự án | Phần cứng, tên miền, tài khoản cổng thanh toán, sinh secret |
| 2 | [Một máy chủ — Docker Compose](02-docker-compose.md) | DevOps | Cài đặt từ A–Z trên Ubuntu, TLS, systemd, sao lưu |
| 3 | [Kubernetes — Helm](03-kubernetes-helm.md) | DevOps / SRE | Cluster, image GHCR, secret, ingress, nâng cấp |
| 4 | [Cấu hình cổng thanh toán VN](04-cong-thanh-toan.md) | Vận hành, kỹ thuật | MoMo, VNPAY, ZaloPay, Viettel Money, payOS, SePay, Pay2S: đăng ký, khoá, webhook, kiểm thử |
| 5 | [Tích hợp cho merchant](05-tich-hop-merchant.md) | Lập trình viên merchant | API key, tạo thanh toán, SDK web/mobile, webhook, plugin |
| 6 | [Vận hành](06-van-hanh.md) | SRE | Giám sát, log, sao lưu/khôi phục, xoay khoá, mở rộng, nâng cấp, xử lý sự cố |
| 7 | [Checklist go-live](07-checklist-go-live.md) | Tất cả | Bảo mật, pháp lý, kiểm thử trước khi nhận tiền thật |
| 8 | [Tham chiếu cấu hình](08-cau-hinh-tham-chieu.md) | DevOps | File cấu hình, biến môi trường, ghi đè router, khoá Helm, cổng mạng |
| 9 | [Bảo mật & hardening](09-bao-mat.md) | DevOps / Bảo mật | Mô hình mối đe doạ, checklist máy chủ/ứng dụng, TLS, PCI DSS, xử lý lộ khoá |

## Script hỗ trợ

| Script | Việc |
|---|---|
| `scripts/install-ubuntu.sh` | Cài đặt trọn gói 1 máy chủ (Docker, build/pull, nginx + TLS, systemd, backup, firewall) |
| `scripts/smoke-test.sh` | Kiểm tra sau triển khai (health, DB/Redis, dashboard, SDK) |
| `scripts/bootstrap-merchant.sh` | Tạo merchant + API key qua admin API |
| `scripts/backup-db.sh` / `scripts/restore-db.sh` | Sao lưu / khôi phục PostgreSQL (tuỳ chọn S3) |
| `scripts/bootstrap.sh`, `apply-patches.sh`, `prepare-docker-context.sh` | Lấy upstream, áp patch, chuẩn bị build Docker |

## File triển khai

| File | Việc |
|---|---|
| `deploy/docker-compose.yml` | Stack đầy đủ |
| `deploy/docker-compose.prod.yml` | Override production (127.0.0.1, không publish DB, log xoay vòng) |
| `deploy/docker-compose.ghcr.yml` | Dùng image GHCR dựng sẵn |
| `deploy/helm/values-hitechpay.yaml` | Kubernetes |

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
