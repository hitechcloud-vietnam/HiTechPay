# 8. Tham chiếu cấu hình

## 8.1 Các file cấu hình

| File | Dùng bởi | Nội dung |
|---|---|---|
| `deploy/.env` (từ `.env.example`) | docker compose | Secret, URL public, cổng, DB, tag image |
| `deploy/docker-compose.yml` | docker compose | Định nghĩa stack (build từ submodule đã vá) |
| `deploy/docker-compose.prod.yml` | docker compose | Override production: cổng 127.0.0.1, không publish DB, log xoay vòng, restart always |
| `deploy/docker-compose.ghcr.yml` | docker compose | Dùng image GHCR dựng sẵn thay vì build |
| `deploy/config/dashboard.toml` | container dashboard | Endpoint API/SDK, logo, cờ tính năng của dashboard |
| `deploy/config/dashboard_theme.json` | container dashboard | Màu sắc, logo |
| `deploy/nginx/hitechpay.conf` | profile `gateway` | nginx trong compose (HTTP, không TLS) |
| `deploy/nginx/host-nginx.conf.template` | `install-ubuntu.sh` | nginx trên máy chủ + certbot |
| `upstream/hyperswitch/config/docker_compose.toml` | router, scheduler | Cấu hình đầy đủ router (DB, Redis, connector base URL, pm_filters…) |
| `deploy/helm/values-hitechpay.yaml` | Helm | Override cho chart `hyperswitch-stack` |
| `branding/brand.json` | `tools/whitelabel.py` | Tên, màu, tên miền, e-mail hỗ trợ |

## 8.2 Biến môi trường `deploy/.env`

| Biến | Mặc định | Ý nghĩa |
|---|---|---|
| `HTP_VERSION` | `latest` | Tag cho image **tự build** |
| `HTP_REGISTRY` | `hitechpay` | Tiền tố image tự build (đặt registry riêng để `docker compose push`) |
| `HTP_IMAGE_TAG` | `main` | Tag image **GHCR** khi dùng `docker-compose.ghcr.yml` (`main`, `sha-<commit>`, `v0.1.0`) |
| `HTP_RUN_ENV` | `sandbox` | `sandbox` / `production` (biến build `RUN_ENV` của router) |
| `HTP_API_PUBLIC_URL` | `http://localhost:8080` | URL public của API — dùng sinh return URL & webhook URL gửi cổng thanh toán |
| `HTP_DASHBOARD_PUBLIC_URL` | `http://localhost:9000` | URL public dashboard — link trong e-mail |
| `HTP_API_PORT` / `HTP_DASHBOARD_PORT` / `HTP_SDK_PORT` | 8080 / 9000 / 9050 | Cổng trên máy chủ |
| `HTP_HTTP_PORT` | 80 | Cổng nginx của profile `gateway` |
| `HTP_PG_PORT` | 5432 | Cổng PostgreSQL publish (bị tắt bởi `docker-compose.prod.yml`) |
| `HTP_DB_USER` / `HTP_DB_PASSWORD` / `HTP_DB_NAME` | `db_user` / `db_pass` / `hyperswitch_db` | PostgreSQL — **đổi mật khẩu** |
| `HTP_ADMIN_API_KEY` | `test_admin` | Khoá API quản trị — **bắt buộc đổi** |
| `HTP_JWT_SECRET` | `change-me-jwt-secret` | Ký JWT phiên dashboard — **bắt buộc đổi** |
| `HTP_MASTER_ENC_KEY` | khoá mẫu | 32 byte hex, mã hoá dữ liệu nhạy cảm — **bắt buộc đổi trước khi có dữ liệu, sau đó không đổi** |
| `HTP_ROUTER_EXTRA_FEATURES` | `--features redis-rs` | Feature cargo khi build router/scheduler — phải có backend Redis |

## 8.3 Ghi đè cấu hình router bằng biến môi trường

Router đọc `config/docker_compose.toml` rồi ghi đè bằng biến `ROUTER__<SECTION>__<KEY>` (chữ hoa, `__` phân cấp).
Thêm vào khối `x-router-env` trong `deploy/docker-compose.yml` để áp dụng cho router và cả 2 scheduler.

| Mục đích | Biến |
|---|---|
| Base URL cổng thanh toán | `ROUTER__CONNECTORS__MOMO__BASE_URL`, `…__VNPAY__BASE_URL`, `…__ZALOPAY__BASE_URL`, `…__VIETTELMONEY__BASE_URL`, `…__PAYOS__BASE_URL`, `…__SEPAY__BASE_URL`, `…__PAY2S__BASE_URL` |
| Tên/logo trong e-mail | `ROUTER__THEME__EMAIL_CONFIG__ENTITY_NAME=HiTechPay`, `ROUTER__THEME__EMAIL_CONFIG__ENTITY_LOGO_URL=https://…/logo.png` |
| Mức log | `ROUTER__LOG__CONSOLE__LEVEL` — mặc định file cấu hình là `DEBUG`; `docker-compose.prod.yml` đặt `INFO` |
| Kết nối DB | `ROUTER__MASTER_DATABASE__HOST`, `…__PORT`, `…__MAX_POOL_SIZE` (mặc định 5) (và `REPLICA_DATABASE`) |
| Redis | `ROUTER__REDIS__HOST`, `ROUTER__REDIS__PORT` |

Base URL production của các cổng (đặt khi `HTP_RUN_ENV=production`):

| Cổng | Sandbox (mặc định) | Production |
|---|---|---|
| MoMo | `https://test-payment.momo.vn/` | `https://payment.momo.vn/` |
| VNPAY (API) | `https://sandbox.vnpayment.vn/merchant_webapi/api/transaction` | `https://merchant.vnpay.vn/merchant_webapi/api/transaction` |
| ZaloPay | `https://sb-openapi.zalopay.vn/` | `https://openapi.zalopay.vn/` |
| Viettel Money (API) | `https://sandbox.viettel.vn/PaymentAPI/webresources/postData` | `https://pay3.viettel.vn/PaymentAPI/webresources/postData` |
| payOS / SePay / Pay2S | giống production | `https://api-merchant.payos.vn/`, `https://my.sepay.vn/`, `https://my.pay2s.vn/` |

Trang thanh toán (redirect) của VNPAY / Viettel Money không nằm trong cấu hình router mà chọn theo metadata/test mode
của connector — xem [04 mục 4.2](04-cong-thanh-toan.md#42-bảng-ánh-xạ-khoá).

## 8.4 Helm — các khoá hay dùng

Tất cả nằm dưới `hyperswitch-app:` trong values của chart `hyperswitch-stack`:

```yaml
hyperswitch-app:
  server:
    run_env: production
    configs:
      connectors:
        momo: { base_url: https://payment.momo.vn/ }
  # PostgreSQL managed (tắt subchart bitnami)
  postgresql:
    enabled: false
  externalPostgresql:
    enabled: true
    primary:
      host: "pg.internal.example"
      auth:
        username: hyperswitch
        password: { _secretRef: { name: hitechpay-db, key: password } }
        plainpassword: { _secretRef: { name: hitechpay-db, key: password } }
        database: hyperswitch
    readOnly:
      enabled: false
  # Redis managed
  redis:
    enabled: false
  externalRedis:
    enabled: true
    host: "redis.internal.example"
    port: "6379"
    auth:
      enabled: true
      username: ""
      password: ""
```

(Tên khoá lấy từ `upstream/hyperswitch-helm/charts/hyperswitch-app/values.yaml`.)

## 8.5 Cổng mạng nội bộ của stack compose

| Dịch vụ | Cổng trong mạng `hitechpay_net` | Publish |
|---|---|---|
| router | 8080 | 8080 (127.0.0.1 với prod override) |
| dashboard | 9000 | 9000 (127.0.0.1) |
| checkout-sdk | 9050 | 9050 (127.0.0.1) |
| pg | 5432 | 5432 (tắt với prod override) |
| redis-standalone | 6379 | không |
| superposition | 8080 | không |
