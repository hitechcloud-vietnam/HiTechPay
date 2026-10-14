# 0. Khởi động nhanh (≈ 30 phút + thời gian build)

Dành cho người muốn có ngay một máy chủ HiTechPay chạy thật với HTTPS. Mọi bước đều dùng script có sẵn
trong repo; giải thích chi tiết ở [02-docker-compose.md](02-docker-compose.md).

## Bạn cần
- 1 máy Ubuntu 22.04/24.04 (≥ 4 vCPU, 8 GB RAM, 50 GB SSD), có IP public.
- 3 bản ghi DNS trỏ về IP đó: `api.<domain>`, `dashboard.<domain>`, `checkout.<domain>`.
- Quyền root (sudo).

## Bước 1 — Cài đặt bằng một lệnh

```bash
curl -fsSL https://raw.githubusercontent.com/hitechcloud-vietnam/HiTechPay/main/scripts/install-ubuntu.sh -o install-ubuntu.sh
sudo DOMAIN=hitechpay.vn ACME_EMAIL=admin@hitechpay.vn bash install-ubuntu.sh
```

Script ([scripts/install-ubuntu.sh](../../scripts/install-ubuntu.sh)) sẽ:

| Bước | Việc |
|---|---|
| 1 | Cài git, Docker Engine, Docker Compose |
| 2 | Clone repo vào `/opt/hitechpay` (nhánh `main`) |
| 3 | Tải upstream + áp dụng patch HiTechPay, chuẩn bị build context |
| 4 | Sinh `deploy/.env` với secret ngẫu nhiên (**sao lưu file này ngay**) |
| 5 | Build image (30–60 phút lần đầu) rồi chạy stack ở chế độ production (cổng chỉ nghe 127.0.0.1) |
| 6 | nginx + chứng chỉ Let's Encrypt cho 3 tên miền |
| 7 | Dịch vụ systemd, sao lưu DB 02:15 hằng ngày, firewall (22/80/443) |

Tuỳ chọn: `USE_GHCR=1` (kéo image dựng sẵn thay vì build — cần package GHCR public hoặc `docker login ghcr.io`),
`RUN_ENV=production`, `NO_TLS=1` (đã có load balancer lo TLS), `REPO_REF=v0.1.0` (cài đúng một bản phát hành).

## Bước 2 — Kiểm tra

```bash
cd /opt/hitechpay
API=https://api.hitechpay.vn DASHBOARD=https://dashboard.hitechpay.vn \
  SDK=https://checkout.hitechpay.vn/HyperLoader.js scripts/smoke-test.sh
```

Kết quả mong đợi: 4 dòng `OK` (health, deep health — DB/Redis, dashboard, SDK).

## Bước 3 — Tạo merchant đầu tiên

```bash
set -a; source deploy/.env; set +a
ADMIN_API_KEY=$HTP_ADMIN_API_KEY API=https://api.hitechpay.vn \
  MERCHANT_ID=shop_demo MERCHANT_NAME="Shop Demo" RETURN_URL=https://shop.example.vn/ket-qua \
  scripts/bootstrap-merchant.sh
```

In ra `merchant_id`, `publishable_key`, `default_profile`, `api_key` (chỉ hiện **một lần** — lưu lại).

Sau đó đăng ký tài khoản quản trị trên `https://dashboard.<domain>` (Sign up) để quản lý bằng giao diện.

## Bước 4 — Gắn cổng thanh toán & thử giao dịch

1. Dashboard → **Connectors → Payment Processors** → chọn SePay / payOS / MoMo… → nhập khoá sandbox
   ([04-cong-thanh-toan.md](04-cong-thanh-toan.md#42-bảng-ánh-xạ-khoá)).
2. Khai báo Webhook URL hiển thị trên dashboard tại trang quản trị của cổng.
3. Tạo giao dịch thử bằng `curl` ở [04 mục 4.4](04-cong-thanh-toan.md#44-kiểm-thử-một-giao-dịch).

## Bước 5 — Trước khi nhận tiền thật
Làm hết [07-checklist-go-live.md](07-checklist-go-live.md).

## Gỡ cài đặt

```bash
cd /opt/hitechpay && $(cat deploy/.compose-cmd) --env-file deploy/.env --profile scheduler down   # giữ dữ liệu
# xoá cả dữ liệu DB:  … down -v
sudo systemctl disable hitechpay && sudo rm /etc/systemd/system/hitechpay.service /etc/cron.d/hitechpay-backup
```
