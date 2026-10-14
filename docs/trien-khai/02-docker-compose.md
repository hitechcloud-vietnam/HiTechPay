# 2. Triển khai một máy chủ (Docker Compose)

Kết quả sau khi làm xong: một máy Ubuntu chạy đầy đủ HiTechPay (API, dashboard, checkout SDK, PostgreSQL,
Redis, Superposition, scheduler), phục vụ HTTPS qua nginx cho 3 tên miền.

Thời gian: ~1–2 giờ, trong đó build image lần đầu 30–60 phút (router Rust là phần lâu nhất).

> ⚡ Muốn tự động toàn bộ các bước dưới đây: `sudo DOMAIN=<domain> ACME_EMAIL=<email> scripts/install-ubuntu.sh`
> — xem [00-khoi-dong-nhanh.md](00-khoi-dong-nhanh.md). Tài liệu này giải thích từng bước để làm tay hoặc tuỳ biến.

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
# môi trường thử (cổng mở trên mọi interface):
COMPOSE="docker compose -f deploy/docker-compose.yml"
# production sau nginx (cổng chỉ nghe 127.0.0.1, không publish DB, log xoay vòng, log level INFO):
COMPOSE="docker compose -f deploy/docker-compose.yml -f deploy/docker-compose.prod.yml"
echo "$COMPOSE" > deploy/.compose-cmd          # các script backup/restore/systemd dùng lại lệnh này

$COMPOSE --env-file deploy/.env build                           # 30–60 phút lần đầu
$COMPOSE --env-file deploy/.env --profile scheduler up -d
```

Thứ tự khởi động tự động: `pg`, `redis-standalone` → `migration-runner` (chạy `just migrate`, tạo schema) →
`superposition` + `superposition-init` → `router` (healthcheck `/health`) → `dashboard`, `checkout-sdk`,
`scheduler-consumer`, `scheduler-producer`.

Theo dõi:

```bash
$COMPOSE ps
$COMPOSE logs -f router
scripts/smoke-test.sh          # health, deep health (DB/Redis), dashboard, SDK
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

## 2.5b Tạo merchant bằng API (tuỳ chọn)

```bash
set -a; source deploy/.env; set +a
ADMIN_API_KEY=$HTP_ADMIN_API_KEY MERCHANT_ID=shop_demo MERCHANT_NAME="Shop Demo" scripts/bootstrap-merchant.sh
```

## 2.6 HTTPS với nginx + Let's Encrypt

HiTechPay có sẵn gateway nginx (`deploy/nginx/hitechpay.conf`, profile `gateway`) định tuyến 3 tên miền tới các
container. Để có TLS, cách đơn giản nhất là dùng nginx trên máy chủ + certbot. Mẫu cấu hình có sẵn ở
`deploy/nginx/host-nginx.conf.template` (thay `__API_HOST__`, `__DASHBOARD_HOST__`, `__CHECKOUT_HOST__`); hoặc viết tay:

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

Docker bỏ qua ufw với cổng đã publish — vì vậy production **phải** chạy kèm `deploy/docker-compose.prod.yml`
(cổng 8080/9000/9050 chỉ nghe 127.0.0.1, PostgreSQL không publish).

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
ExecStart=/bin/sh -c '$(cat deploy/.compose-cmd) --env-file deploy/.env --profile scheduler up -d'
ExecStop=/bin/sh -c '$(cat deploy/.compose-cmd) --env-file deploy/.env --profile scheduler down'
TimeoutStartSec=0

[Install]
WantedBy=multi-user.target
EOF
sudo systemctl daemon-reload && sudo systemctl enable --now hitechpay
```

## 2.8 Sao lưu PostgreSQL

Script [scripts/backup-db.sh](../../scripts/backup-db.sh) chạy `pg_dump -Fc` trong container `pg`, giữ `KEEP_DAYS` ngày,
tuỳ chọn đẩy lên S3 (`S3_URI=s3://bucket/path`, cần aws cli):

```bash
BACKUP_DIR=/var/backups/hitechpay KEEP_DAYS=14 scripts/backup-db.sh
echo '15 2 * * * root cd /opt/hitechpay && BACKUP_DIR=/var/backups/hitechpay scripts/backup-db.sh >> /var/log/hitechpay-backup.log 2>&1' \
  | sudo tee /etc/cron.d/hitechpay-backup
```

Khôi phục: `scripts/restore-db.sh /var/backups/hitechpay/db-YYYYMMDD-HHMMSS.dump` (tự dừng router/scheduler, khôi phục,
khởi động lại). **Sao lưu riêng `deploy/.env`** (chứa master key) ở nơi an toàn khác.

## 2.9 Dùng image dựng sẵn từ GHCR (không build trên máy chủ)

Workflow `.github/workflows/images.yml` đẩy image lên `ghcr.io/hitechcloud-vietnam/hitechpay-{router,scheduler,dashboard,checkout-sdk}`
(tag `main`, `sha-<commit>`, bản phát hành như `v0.1.0`, và phiên bản upstream). File override có sẵn:
[deploy/docker-compose.ghcr.yml](../../deploy/docker-compose.ghcr.yml), chọn tag bằng `HTP_IMAGE_TAG` trong `deploy/.env`.

```bash
COMPOSE="docker compose -f deploy/docker-compose.yml -f deploy/docker-compose.prod.yml -f deploy/docker-compose.ghcr.yml"
echo "$COMPOSE" > deploy/.compose-cmd
$COMPOSE --env-file deploy/.env --profile scheduler pull
$COMPOSE --env-file deploy/.env --profile scheduler up -d
```

Vẫn cần `scripts/bootstrap.sh --core` vì migration và cấu hình router được mount từ `upstream/hyperswitch`.
Nếu package GHCR để private: `echo $GHCR_TOKEN | docker login ghcr.io -u <user> --password-stdin`.

## 2.10 Cập nhật phiên bản

```bash
cd /opt/hitechpay
git pull origin main
scripts/apply-patches.sh && scripts/prepare-docker-context.sh
scripts/backup-db.sh                                                    # luôn sao lưu trước
$(cat deploy/.compose-cmd) --env-file deploy/.env build                 # hoặc `pull` nếu dùng GHCR
$(cat deploy/.compose-cmd) --env-file deploy/.env --profile scheduler up -d   # migration chạy lại tự động
scripts/smoke-test.sh
```

Luôn sao lưu DB trước khi cập nhật.

## 2.11 Bước tiếp theo
→ [Cấu hình cổng thanh toán](04-cong-thanh-toan.md) → [Tích hợp merchant](05-tich-hop-merchant.md) →
[Checklist go-live](07-checklist-go-live.md)
