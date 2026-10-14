# Triển khai / Deployment

## Yêu cầu
Docker 24+ với Compose v2, 4 vCPU / 8 GB RAM (build router lần đầu cần ~30–60 phút và ~20 GB đĩa).

## Single host (docker compose)

```bash
scripts/bootstrap.sh --core
cp deploy/.env.example deploy/.env && $EDITOR deploy/.env     # đổi toàn bộ secret
docker compose -f deploy/docker-compose.yml --env-file deploy/.env up -d --build
docker compose -f deploy/docker-compose.yml --env-file deploy/.env --profile bootstrap up create-default-user
```

Profiles: `scheduler` (retry/scheduled jobs), `gateway` (nginx on :80 với host `api.*`, `dashboard.*`, `checkout.*`),
`bootstrap` (tạo user mặc định).

Router đọc `upstream/hyperswitch/config/docker_compose.toml` (đã có base URL cho 7 connector VN);
ghi đè bằng biến môi trường `ROUTER__<SECTION>__<KEY>`, ví dụ:

```
ROUTER__CONNECTORS__MOMO__BASE_URL=https://payment.momo.vn/
ROUTER__CONNECTORS__VNPAY__BASE_URL=https://merchant.vnpay.vn/merchant_webapi/api/transaction
ROUTER__CONNECTORS__ZALOPAY__BASE_URL=https://openapi.zalopay.vn/
```

Production base URLs có sẵn trong `config/deployments/production.toml`.

## Kubernetes
Dùng chart trong `upstream/hyperswitch-helm`, thay image bằng các image HiTechPay
(`${HTP_REGISTRY}/router`, `/scheduler`, `/dashboard`, `/checkout-sdk`) build từ compose:

```bash
docker compose -f deploy/docker-compose.yml build
docker compose -f deploy/docker-compose.yml push      # sau khi đặt HTP_REGISTRY
```

## Sau khi chạy
1. Đăng nhập dashboard, tạo merchant/profile.
2. *Connectors → Payment processors*: chọn MoMo / VNPAY / ZaloPay / … nhập khoá (xem [connectors-vn.md](connectors-vn.md)).
3. Đăng ký webhook URL `https://<api>/webhooks/<merchant_id>/<merchant_connector_id>` tại từng cổng
   (VNPAY: IPN URL trong cổng quản trị; SePay/Pay2S: webhook kèm API key / token).
4. Bật phương thức thanh toán `viet_qr`, `momo`, `momo_atm`, `local_bank_redirect` cho profile, tiền tệ VND.
5. Tích hợp SDK: `sdks/node` hoặc `sdks/php` (server), `HyperLoader.js` (trình duyệt, `locale: "vi"`).

## Bảo mật
- Đổi `HTP_ADMIN_API_KEY`, `HTP_JWT_SECRET`, `HTP_MASTER_ENC_KEY` (`openssl rand -hex 32`).
- Đặt TLS phía trước gateway; chỉ public cổng 80/443.
- Bật card vault (`upstream/hyperswitch-card-vault`) nếu lưu thẻ.
