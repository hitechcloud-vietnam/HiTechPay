# Changelog

Định dạng theo [Keep a Changelog](https://keepachangelog.com/vi/1.1.0/), phiên bản theo [SemVer](https://semver.org/lang/vi/).
Phát hành: đẩy tag `vX.Y.Z` (hoặc chạy tay workflow *Release* trên `main` với `tag: vX.Y.Z`) → GitHub Release từ mục tương ứng bên dưới,
workflow *Images* build image GHCR cùng tag.

## [Unreleased]
- Connector OnePay, AppotaPay, 9Pay và sửa ZaloPay lúc nửa đêm — đang làm ([tasks/003](tasks/003-connectors-batch2.md)).

## [0.1.0] - 2026-10-14

Bản phát hành đầu tiên (pre-release) của **HiTechPay Platform** — white-label của Juspay Hyperswitch cho thị trường Việt Nam.

### Thành phần upstream (ghim phiên bản)
| Repo | Phiên bản |
|---|---|
| hyperswitch (router) | v1.127.0 |
| hyperswitch-control-center (dashboard) | v1.38.9 |
| hyperswitch-web (checkout SDK) | v0.134.0 |
| hyperswitch-client-core (mobile SDK core) | v1.12.2 |
| hyperswitch-woocommerce-plugin | v1.6.1 |

### Thêm mới
- **7 cổng thanh toán Việt Nam**: MoMo (ví + ATM), VNPAY (VNPAY-QR + ngân hàng nội địa), ZaloPay, Viettel Money (beta),
  payOS, SePay, Pay2S (beta) — thanh toán, đồng bộ trạng thái, hoàn tiền (cổng hỗ trợ), webhook có xác thực chữ ký.
- `vn_utils`: VietQR EMVCo + CRC16, danh bạ BIN NAPAS, HMAC SHA1/256/512, giờ GMT+7, mã đối soát chuyển khoản `HTP…`.
- 2 hook lõi Hyperswitch: authorize / psync cục bộ cho cổng redirect tự ký và chuyển khoản VietQR.
- Dashboard thương hiệu HiTechPay, cấu hình được 7 cổng (euclid WASM build lại).
- Checkout web SDK: tiếng Việt, VietQR / MoMo ATM / ngân hàng nội địa, thương hiệu.
- Mobile SDK core: tiếng Việt, màn hình VietQR, MoMo ATM, ngân hàng nội địa, thương hiệu.
- SDK server: `@hitechpay/node`, `hitechpay/hitechpay-php` (webhook, VietQR, retry).
- Plugin: HiTechPay for WooCommerce, module WHMCS.
- Triển khai: Docker Compose, nginx gateway, Helm values, workflow build image GHCR, workflow phát hành.
- Tài liệu: hướng dẫn triển khai chi tiết `docs/trien-khai/`, `plan.md`, `tasks/`.

### Sửa lỗi
- WooCommerce (upstream): số tiền VND bị nhân 100; hoàn tiền và capture thủ công luôn báo lỗi; so sánh chữ ký webhook không constant-time.
- Mobile SDK (upstream): body thanh toán MoMo sai định dạng.
- Image router: build với `--features redis-rs` (Dockerfile upstream không bật backend Redis khi build release).
- Docker Compose: biến `HTP_DB_*` và secret được truyền đúng vào router và scheduler.

### Hạn chế đã biết
- Các cổng chưa được kiểm thử với sandbox thật ([tasks/004](tasks/004-sandbox-e2e-gateways.md)); Viettel Money và Pay2S cần
  đối chiếu đặc tả đối tác ([005](tasks/005-viettel-money-v2.md), [006](tasks/006-pay2s-verify.md)).
- WooCommerce chưa có file `.mo` tiếng Việt ([009](tasks/009-woocommerce-release.md)).
- Image GHCR cần đặt public; nhánh mặc định của repo cần chuyển sang `main` ([001](tasks/001-default-branch-main.md), [002](tasks/002-images-ci-ghcr.md)).
- Chưa sẵn sàng nhận tiền thật trước khi hoàn tất [checklist go-live](docs/trien-khai/07-checklist-go-live.md) và đánh giá pháp lý ([007](tasks/007-legal-compliance.md)).

### Tệp đính kèm
| Tệp | Nội dung |
|---|---|
| `hitechpay-patches-v0.1.0.tar.gz` | Patch series + scripts + tools + branding |
| `hitechpay-deploy-v0.1.0.tar.gz` | Docker Compose, nginx, Helm values, tài liệu |
| `hitechpay-node-0.1.0.tgz` | SDK Node.js (`npm install ./hitechpay-node-0.1.0.tgz`) |
| `hitechpay-php-v0.1.0.zip` | SDK PHP |
| `hitechpay-whmcs-v0.1.0.zip` | Module WHMCS (giải nén vào thư mục gốc WHMCS) |
| `hitechpay-woocommerce-v0.1.0.zip` | Plugin WooCommerce (WordPress → Plugins → Upload) |
| `SHA256SUMS` | Checksum |

Image Docker: `ghcr.io/hitechcloud-vietnam/hitechpay-{router,scheduler,dashboard,checkout-sdk}` tag `v0.1.0`
(và tag phiên bản upstream `v1.127.0` / `v1.38.9` / `v0.134.0`).

[Unreleased]: https://github.com/hitechcloud-vietnam/HiTechPay/compare/v0.1.0...main
[0.1.0]: https://github.com/hitechcloud-vietnam/HiTechPay/releases/tag/v0.1.0
