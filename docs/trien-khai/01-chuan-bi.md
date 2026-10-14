# 1. Chuẩn bị

## 1.1 Hạ tầng

| Thành phần | Sandbox / demo | Production tối thiểu | Production khuyến nghị |
|---|---|---|---|
| Máy build image | 4 vCPU, 8 GB RAM, 40 GB SSD (chỉ cần khi tự build) | — (dùng GitHub Actions) | — |
| Máy chạy (Compose) | 4 vCPU, 8 GB RAM, 50 GB SSD | 8 vCPU, 16 GB RAM, 100 GB SSD | Dùng Kubernetes |
| Kubernetes | — | 3 node × (4 vCPU, 8 GB) | 3+ node × (8 vCPU, 16 GB), nhiều AZ |
| PostgreSQL | container | container + sao lưu hằng ngày | Managed (≥ 2 vCPU, 8 GB, HA, PITR) |
| Redis | container | container | Managed (≥ 2 GB, replica) |
| Hệ điều hành | Ubuntu 22.04/24.04 LTS | Ubuntu 22.04/24.04 LTS | — |

Mạng:
- Cổng vào: chỉ **80/443** (và 22 cho quản trị, giới hạn IP). Không mở 5432/6379/8080/9000/9050 ra Internet.
- Cổng ra: HTTPS 443 tới các cổng thanh toán (`*.momo.vn`, `*.vnpayment.vn`, `*.vnpay.vn`, `*.zalopay.vn`,
  `*.viettel.vn`, `api-merchant.payos.vn`, `my.sepay.vn`, `qr.sepay.vn`, `my.pay2s.vn`, `img.vietqr.io`),
  tới `ghcr.io`/`docker.io` (kéo image) và `github.com` (bootstrap).
- Một số cổng (VNPAY, MoMo, ZaloPay…) yêu cầu **đăng ký IP máy chủ** gọi API — chuẩn bị IP tĩnh (NAT gateway / Elastic IP).

## 1.2 Tên miền và DNS

Ví dụ với tên miền `hitechpay.vn` (thay bằng tên miền của bạn):

| Bản ghi | Trỏ tới | Dùng cho |
|---|---|---|
| `api.hitechpay.vn` | IP máy chủ / ingress | Router API, webhook từ cổng thanh toán |
| `dashboard.hitechpay.vn` | IP máy chủ / ingress | Dashboard quản trị |
| `checkout.hitechpay.vn` | IP máy chủ / ingress | `HyperLoader.js` (Checkout SDK) |
| `sandbox-api.hitechpay.vn` (tuỳ chọn) | môi trường sandbox | Thử nghiệm của merchant |

Sau khi đổi tên miền, cập nhật `branding/brand.json` (`domains`, `support_email`, `website`) và chạy
`python3 tools/whitelabel.py` nếu muốn các liên kết trong giao diện trỏ đúng tên miền mới.

## 1.3 Tài khoản cổng thanh toán (làm song song — thường mất 1–4 tuần)

| Cổng | Đăng ký | Hồ sơ thường cần | Bạn sẽ nhận được |
|---|---|---|---|
| MoMo | business.momo.vn → *Đăng ký merchant* | ĐKKD, CCCD người đại diện, website/app, tài khoản ngân hàng | Partner Code, Access Key, Secret Key (sandbox ngay, production sau thẩm định) |
| VNPAY | vnpay.vn → *Đăng ký cổng thanh toán* (sandbox: sandbox.vnpayment.vn/devreg) | ĐKKD, hợp đồng dịch vụ | `vnp_TmnCode`, `vnp_HashSecret`, tài khoản trang quản trị merchant |
| ZaloPay | zalopay.vn/doanh-nghiep → *Đăng ký* | ĐKKD, website | `app_id`, `key1`, `key2` |
| Viettel Money | Liên hệ bộ phận đối tác Viettel Money | ĐKKD, hợp đồng | `merchant_code`, `access_code`, `hash_key` + tài liệu tích hợp riêng |
| payOS | my.payos.vn (đăng nhập, liên kết tài khoản ngân hàng) | Tài khoản ngân hàng doanh nghiệp/cá nhân | Client ID, API Key, Checksum Key |
| SePay | my.sepay.vn → liên kết tài khoản ngân hàng | Tài khoản ngân hàng hỗ trợ API | API Token, webhook (API key tự đặt) |
| Pay2S | pay2s.vn → đăng ký, liên kết ngân hàng | Tài khoản ngân hàng | API Token, webhook token |

Lưu ý: dùng **thông tin sandbox** để dựng và kiểm thử trước; chỉ thay bằng khoá production ở bước go-live.

## 1.4 Sinh secret

Chạy trên máy quản trị (lưu vào trình quản lý mật khẩu / Vault, **không** commit vào git):

```bash
echo "HTP_ADMIN_API_KEY=$(openssl rand -hex 32)"
echo "HTP_JWT_SECRET=$(openssl rand -hex 32)"
echo "HTP_MASTER_ENC_KEY=$(openssl rand -hex 32)"   # 32 byte hex — KHÔNG được đổi sau khi đã có dữ liệu
echo "HTP_DB_PASSWORD=$(openssl rand -base64 24 | tr -d '/+=')"
```

| Secret | Ý nghĩa | Đổi được không? |
|---|---|---|
| Admin API key | Gọi API quản trị (tạo merchant, API key…) | Được (khởi động lại router) |
| JWT secret | Ký phiên đăng nhập dashboard | Được (mọi người phải đăng nhập lại) |
| Master encryption key | Mã hoá khoá cổng thanh toán, dữ liệu nhạy cảm trong DB | **Không** — mất key = mất dữ liệu đã mã hoá |
| DB password | PostgreSQL | Được (cập nhật cả DB và router) |

## 1.5 Công cụ trên máy triển khai

```bash
sudo apt-get update && sudo apt-get install -y git curl ca-certificates gnupg python3 make jq
# Docker Engine + Compose v2
curl -fsSL https://get.docker.com | sudo sh
sudo usermod -aG docker $USER && newgrp docker
docker compose version     # cần v2.20+
```

Với Kubernetes cần thêm `kubectl` (≥ 1.24) và `helm` (≥ 3.12).
