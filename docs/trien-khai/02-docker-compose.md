# 2. Triển khai một máy chủ (Docker Compose)

Kết quả sau khi làm xong: một máy Ubuntu chạy đầy đủ HiTechPay (API, dashboard, checkout SDK, PostgreSQL,
Redis, Superposition, scheduler), phục vụ HTTPS qua nginx cho 3 tên miền.

Thời gian: ~1–2 giờ, trong đó build image lần đầu 30–60 phút (router Rust là phần lâu nhất).

## 2.1 Lấy mã nguồn

```bash
sudo mkdir -p /opt/hitechpay && sudo chown $USER /opt/hitechpay
git clone https://github.com/hitechcloud-vietnam/HiTechPay.git /opt/hitechpay
cd /opt/hitechpay
git checkout main
```

## 2.2 Tải upstream và áp dụng patch HiTechPay

```bash
scripts/bootstrap.sh --core
```

Lệnh này:
1. Tải (shallow) các submodule có patch: `hyperswitch`, `hyperswitch-web`, `hyperswitch-control-center`,
   `hyperswitch-client-core`, `hyperswitch-woocommerce-plugin` — đúng commit upstream đã ghim.
2. Trong mỗi submodule tạo nhánh `hitechpay` và áp dụng `patches/<repo>/*.patch` bằng `git am`.

Kiểm tra:

```bash
git -C upstream/hyperswitch log --oneline -5      # phải thấy các commit "feat(connectors)…"
ls upstream/hyperswitch/crates/hyperswitch_connectors/src/connectors | grep -E "momo|vnpay|zalopay|sepay|payos"
```

Tiếp theo, chuẩn bị build context cho Docker (Dockerfile của router đọc thông tin git khi biên dịch, Dockerfile
của web SDK chạy `git submodule update`, nên thư mục `.git` của submodule phải nằm trong build context):

```bash
scripts/prepare-docker-context.sh
```

## 2.3 Cấu hình

```bash
cp deploy/.env.example deploy/.env
chmod 600 deploy/.env
nano deploy/.env
```

Các biến **bắt buộc đổi**:

| Biến | Giá trị |
|---|---|
| `HTP_API_PUBLIC_URL` | `https://api.<domain>` — dùng để sinh return URL và webhook URL gửi cho cổng thanh toán |
| `HTP_DASHBOARD_PUBLIC_URL` | `https://dashboard.<domain>` — link trong e-mail mời, đặt lại mật khẩu |
| `HTP_ADMIN_API_KEY`, `HTP_JWT_SECRET`, `HTP_MASTER_ENC_KEY` | secret sinh ở [bước 1.4](01-chuan-bi.md#14-sinh-secret) |
| `HTP_DB_PASSWORD` | mật khẩu PostgreSQL mạnh |
| `HTP_RUN_ENV` | `sandbox` khi thử nghiệm, `production` khi chạy thật |

Biến tuỳ chọn: cổng (`HTP_*_PORT`), registry/tag image (`HTP_REGISTRY`, `HTP_VERSION`), tính năng cargo bổ sung.

Cấu hình router nằm ở `upstream/hyperswitch/config/docker_compose.toml` (đã chứa base URL **sandbox** của các cổng VN).
Mọi khoá trong file có thể ghi đè bằng biến môi trường `ROUTER__<SECTION>__<KEY>` — thêm vào phần
`x-router-env` của `deploy/docker-compose.yml` hoặc vào `deploy/.env` rồi tham chiếu. Ví dụ chuyển các cổng sang
production:

```yaml
  ROUTER__CONNECTORS__MOMO__BASE_URL: https://payment.momo.vn/
  ROUTER__CONNECTORS__VNPAY__BASE_URL: https://merchant.vnpay.vn/merchant_webapi/api/transaction
  ROUTER__CONNECTORS__ZALOPAY__BASE_URL: https://openapi.zalopay.vn/
  ROUTER__CONNECTORS__VIETTELMONEY__BASE_URL: https://pay3.viettel.vn/PaymentAPI/webresources/postData
```

(payOS, SePay, Pay2S dùng cùng URL cho sandbox và production — phân biệt bằng khoá.)

Dashboard đọc `deploy/config/dashboard.toml` và `deploy/config/dashboard_theme.json` (mount vào container). Khi
có tên miền thật, sửa trong `dashboard.toml`:

```toml
[default.endpoints]
api_url="https://api.<domain>"
sdk_url="https://checkout.<domain>/HyperLoader.js"
```

## 2.4 Build và khởi động

```bash
cd /opt/hitechpay
docker compose -f deploy/docker-compose.yml --env-file deploy/.env build      # 30–60 phút lần đầu
docker compose -f deploy/docker-compose.yml --env-file deploy/.env --profile scheduler up -d
```

Thứ tự khởi động tự động: `pg`, `redis-standalone` → `migration-runner` (chạy `just migrate`, tạo schema) →
`superposition` + `superposition-init` → `router` (healthcheck `/health`) → `dashboard`, `checkout-sdk`,
`scheduler-consumer`, `scheduler-producer`.

Theo dõi:

```bash
docker compose -f deploy/docker-compose.yml ps
docker compose -f deploy/docker-compose.yml logs -f router
curl -s http://localhost:8080/health          # → "health is good"
```

Nếu `migration-runner` lỗi: `docker compose … logs migration-runner` (thường do sai mật khẩu DB hoặc mạng ra ngoài
bị chặn khi tải `diesel`/`just`).

## 2.5 Tạo tài khoản quản trị đầu tiên

Cách 1 — đăng ký trên dashboard: mở `http://<ip>:9000` (hoặc `https://dashboard.<domain>` sau bước 2.6) → *Sign up*.

Cách 2 — script tạo user mẫu:

```bash
docker compose -f deploy/docker-compose.yml --env-file deploy/.env --profile bootstrap up create-default-user
```

Script upstream tạo `demo@hyperswitch.com` / `Hyperswitch@123` — **đổi mật khẩu ngay** hoặc xoá user này trước
khi public hệ thống.

## 2.6 HTTPS với nginx + Let's Encrypt

HiTechPay có sẵn gateway nginx (`deploy/nginx/hitechpay.conf`, profile `gateway`) định tuyến 3 tên miền tới các
container. Để có TLS, cách đơn giản nhất là dùng nginx trên máy chủ + certbot:

```bash
sudo apt-get install -y nginx certbot python3-certbot-nginx
sudo tee /etc/nginx/sites-available/hitechpay.conf >/dev/null <<'EOF'
server { listen 80; server_name api.hitechpay.vn;
  client_max_body_size 10m;
  location / { proxy_pass http://127.0.0.1:8080; proxy_set_header Host $host;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for; proxy_set_header X-Forwarded-Proto $scheme;
    proxy_set_header X-Real-IP $remote_addr; } }
server { listen 80; server_name dashboard.hitechpay.vn;
  location / { proxy_pass http://127.0.0.1:9000; proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade; proxy_set_header Connection "upgrade"; proxy_set_header Host $host; } }
server { listen 80; server_name checkout.hitechpay.vn;
  location / { proxy_pass http://127.0.0.1:9050; proxy_set_header Host $host; } }
EOF
sudo ln -sf /etc/nginx/sites-available/hitechpay.conf /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d api.hitechpay.vn -d dashboard.hitechpay.vn -d checkout.hitechpay.vn \
  --redirect --agree-tos -m admin@hitechpay.vn
```

Sau khi có HTTPS, chỉ để các cổng ứng dụng nghe trên localhost — trong `deploy/.env` không cần đổi, nhưng
**chặn** 8080/9000/9050/5432 bằng firewall:

```bash
sudo ufw default deny incoming && sudo ufw allow 22/tcp && sudo ufw allow 80,443/tcp && sudo ufw enable
```

(Docker bỏ qua ufw với cổng đã publish; để chắc chắn, đổi `ports` thành `127.0.0.1:8080:8080`… trong
`deploy/docker-compose.yml`, hoặc xoá dòng `ports` của `pg`.)

## 2.7 Tự khởi động cùng hệ thống (systemd)

```bash
sudo tee /etc/systemd/system/hitechpay.service >/dev/null <<'EOF'
[Unit]
Description=HiTechPay Platform
Requires=docker.service
After=docker.service network-online.target

[Service]
Type=oneshot
RemainAfterExit=yes
WorkingDirectory=/opt/hitechpay
ExecStart=/usr/bin/docker compose -f deploy/docker-compose.yml --env-file deploy/.env --profile scheduler up -d
ExecStop=/usr/bin/docker compose -f deploy/docker-compose.yml --env-file deploy/.env --profile scheduler down
TimeoutStartSec=0

[Install]
WantedBy=multi-user.target
EOF
sudo systemctl daemon-reload && sudo systemctl enable --now hitechpay
```

## 2.8 Sao lưu PostgreSQL

```bash
sudo mkdir -p /var/backups/hitechpay
sudo tee /etc/cron.d/hitechpay-backup >/dev/null <<'EOF'
15 2 * * * root docker exec $(docker ps -qf name=hitechpay-pg) pg_dump -U db_user -Fc hyperswitch_db > /var/backups/hitechpay/db-$(date +\%F).dump && find /var/backups/hitechpay -name 'db-*.dump' -mtime +14 -delete
EOF
```

Đồng bộ thư mục sao lưu ra ngoài máy (S3/MinIO/NAS) và **sao lưu riêng `deploy/.env`** (chứa master key).
Khôi phục: xem [06-van-hanh.md](06-van-hanh.md#sao-lưu-và-khôi-phục).

## 2.9 Dùng image dựng sẵn từ GHCR (không build trên máy chủ)

Workflow `.github/workflows/images.yml` đẩy image lên `ghcr.io/hitechcloud-vietnam/hitechpay-{router,scheduler,dashboard,checkout-sdk}`
mỗi khi push lên `main`. Tạo file `deploy/docker-compose.ghcr.yml`:

```yaml
services:
  router:             { image: ghcr.io/hitechcloud-vietnam/hitechpay-router:latest,       build: !reset null }
  scheduler-consumer: { image: ghcr.io/hitechcloud-vietnam/hitechpay-scheduler:latest,    build: !reset null }
  scheduler-producer: { image: ghcr.io/hitechcloud-vietnam/hitechpay-scheduler:latest }
  dashboard:          { image: ghcr.io/hitechcloud-vietnam/hitechpay-dashboard:latest,    build: !reset null }
  checkout-sdk:       { image: ghcr.io/hitechcloud-vietnam/hitechpay-checkout-sdk:latest, build: !reset null }
```

```bash
docker compose -f deploy/docker-compose.yml -f deploy/docker-compose.ghcr.yml --env-file deploy/.env --profile scheduler pull
docker compose -f deploy/docker-compose.yml -f deploy/docker-compose.ghcr.yml --env-file deploy/.env --profile scheduler up -d
```

Vẫn cần `scripts/bootstrap.sh --core` vì migration và cấu hình router được mount từ `upstream/hyperswitch`.
Nếu package GHCR để private: `echo $GHCR_TOKEN | docker login ghcr.io -u <user> --password-stdin`.

## 2.10 Cập nhật phiên bản

```bash
cd /opt/hitechpay
git pull origin main
scripts/apply-patches.sh && scripts/prepare-docker-context.sh
docker compose -f deploy/docker-compose.yml --env-file deploy/.env build
docker compose -f deploy/docker-compose.yml --env-file deploy/.env --profile scheduler up -d   # migration chạy lại tự động
```

Luôn sao lưu DB trước khi cập nhật.

## 2.11 Bước tiếp theo
→ [Cấu hình cổng thanh toán](04-cong-thanh-toan.md) → [Tích hợp merchant](05-tich-hop-merchant.md) →
[Checklist go-live](07-checklist-go-live.md)
