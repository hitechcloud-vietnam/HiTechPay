# HiTechPay Platform

**Cổng thanh toán hợp nhất cho doanh nghiệp Việt Nam** — một bản white-label của
[Juspay Hyperswitch](https://github.com/juspay/hyperswitch) (payment orchestrator mã nguồn mở,
Apache-2.0), bổ sung các cổng thanh toán Việt Nam: **MoMo, VNPAY, ZaloPay, Viettel Money,
payOS, SePay, Pay2S, OnePay, AppotaPay, 9Pay**.

Phát triển bởi Pho Tue Software Solutions JSC & HiTechCloud Vietnam.

> English summary: HiTechPay is a white-labelled distribution of Juspay Hyperswitch with
> native Vietnamese payment connectors, a Vietnamese-localised checkout SDK and a rebranded
> merchant dashboard. Upstream code is consumed as pinned git submodules; every HiTechPay change
> lives in `patches/` and is re-applied with `scripts/bootstrap.sh`.

---

## Thành phần

| Thành phần | Upstream (submodule) | HiTechPay thay đổi |
|---|---|---|
| Payment router (API, routing, vault, webhooks) | `upstream/hyperswitch` @ v1.127.0 | 10 connector Việt Nam, `vn_utils` (VietQR, HMAC, giờ GMT+7), 2 hook lõi cho cổng redirect / chuyển khoản |
| Merchant dashboard | `upstream/hyperswitch-control-center` @ v1.38.9 | Thương hiệu HiTechPay, 10 connector mới, euclid WASM build lại |
| Checkout web SDK | `upstream/hyperswitch-web` @ v0.134.0 | Ngôn ngữ tiếng Việt, VietQR / MoMo ATM / ngân hàng nội địa, thương hiệu |
| Mobile SDK (React Native core, Android, iOS, RN wrapper) | `hyperswitch-client-core`, `hyperswitch-sdk-android`, `hyperswitch-sdk-ios`, `react-native-hyperswitch` | tham chiếu (chưa vá) |
| Card vault, encryption service | `hyperswitch-card-vault`, `hyperswitch-encryption-service` | tham chiếu |
| Helm charts | `hyperswitch-helm` | tham chiếu |
| Connector service (Prism), WooCommerce plugin, SDK utils, docs | `hyperswitch-prism`, `hyperswitch-woocommerce-plugin`, `hyperswitch-sdk-utils`, `hyperswitch-docs` | tham chiếu |
| Server SDK | — | `sdks/node` (`@hitechpay/node`), `sdks/php` (`hitechpay/hitechpay-php`) |

## Cổng thanh toán Việt Nam

| Connector | Phương thức (Hyperswitch) | Thanh toán | Đồng bộ trạng thái | Hoàn tiền | Webhook |
|---|---|---|---|---|---|
| `momo` | wallet/`momo`, card_redirect/`momo_atm` | API create → redirect + QR | API query | ✅ API | IPN HMAC-SHA256 |
| `vnpay` | real_time_payment/`viet_qr` (VNPAY-QR), bank_redirect/`local_bank_redirect` | URL ký HMAC-SHA512 (tạo cục bộ) | `querydr` | ✅ API | IPN (GET) |
| `zalopay` | `viet_qr`, `local_bank_redirect` | API v2/create → redirect + QR | API v2/query | ✅ API + query_refund | callback MAC key2 |
| `viettelmoney` | `local_bank_redirect`, `viet_qr` | URL ký HMAC-SHA1 (cục bộ) | từ IPN / tham số return đã ký | portal | IPN checksum |
| `payos` | `viet_qr` | API payment-requests → checkout + VietQR | API get | ❌ (không có API) | chữ ký HMAC-SHA256 |
| `sepay` | `viet_qr` (chuyển khoản) | VietQR tạo cục bộ, mã đối soát `HTP…` | API transactions | ❌ | `Authorization: Apikey` |
| `pay2s` | `viet_qr` (chuyển khoản) | VietQR tạo cục bộ, mã đối soát `HTP…` | từ webhook | ❌ | `Authorization: Bearer` |
| `onepay` (beta) | `local_bank_redirect` (nội địa NAPAS), card_redirect/`card_redirect` (quốc tế) | URL `vpc_*` ký HMAC-SHA256 (cục bộ) | QueryDR | ✅ API | IPN `vpc_SecureHash` |
| `appotapay` (beta) | `local_bank_redirect`, `card_redirect`, `viet_qr`, wallet/`momo` | API v2 orders/payment (JWT) → redirect | API transaction | ✅ API + refund status | IPN HMAC-SHA256 |
| `ninepay` (beta) | `local_bank_redirect`, `card_redirect`, `viet_qr` | portal base64 JSON + HMAC-SHA256 (cục bộ) | API inquire | ✅ API | IPN checksum SHA256 |

Chi tiết cấu hình, ánh xạ khoá và giới hạn: [docs/connectors-vn.md](docs/connectors-vn.md).

## Bắt đầu nhanh

```bash
git clone https://github.com/hitechcloud-vietnam/HiTechPay && cd HiTechPay
scripts/bootstrap.sh --core          # tải hyperswitch, web, control-center + áp dụng patches
cp deploy/.env.example deploy/.env   # đổi secret!
docker compose -f deploy/docker-compose.yml --env-file deploy/.env up -d --build
```

- API: http://localhost:8080 · Dashboard: http://localhost:9000 · SDK: http://localhost:9050/HyperLoader.js
- Hướng dẫn triển khai đầy đủ: [docs/deployment.md](docs/deployment.md)

## Phát triển

```bash
# sửa code trong submodule (nhánh hitechpay), commit, rồi:
scripts/export-patches.sh            # ghi lại patches/<repo>/*.patch
# nâng cấp upstream:
scripts/upgrade-upstream.sh hyperswitch v1.128.0
# đăng ký connector mới vào router / dashboard:
python3 tools/register_connectors.py upstream/hyperswitch
python3 tools/register_control_center.py upstream/hyperswitch-control-center
# áp dụng thương hiệu (branding/brand.json):
python3 tools/whitelabel.py
```

Kiến trúc và quy trình patch: [docs/architecture.md](docs/architecture.md) ·
White-label: [docs/whitelabel.md](docs/whitelabel.md) · Lộ trình: [docs/roadmap.md](docs/roadmap.md)

## Kiểm thử đã chạy

- `cargo check -p router` (kèm `--tests`): không lỗi, không cảnh báo trong code mới.
- `cargo test -p hyperswitch_connectors --lib` (lọc theo connector VN + `vn_utils`): 32/32 pass.
- Dashboard: `npx rescript` pass. Web SDK: `npx rescript` + `npm run build` pass.
- `sdks/node`: 26/26 test. `sdks/php`: 19/19 test.
- Patch series áp dụng lại lên commit upstream ghim tạo ra cây mã giống hệt.

> ⚠️ Các connector chưa được chạy với tài khoản sandbox thật (môi trường build không truy cập
> được các cổng VN). Trước khi chạy thật, hãy kiểm tra theo [docs/connectors-vn.md](docs/connectors-vn.md),
> đặc biệt Viettel Money và Pay2S (đặc tả do đối tác cung cấp riêng).

## Giấy phép

Mã upstream giữ nguyên giấy phép Apache-2.0 của Juspay (xem từng submodule). Phần bổ sung của
HiTechPay phát hành theo Apache-2.0. "Hyperswitch" là thương hiệu của Juspay; HiTechPay là sản phẩm
độc lập, không liên kết chính thức với Juspay.
