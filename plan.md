# HiTechPay — Kế hoạch & trạng thái dự án

Cập nhật: 2026-10-14 · Nhánh chính: **`main`** · Chi tiết từng việc dở dang: [tasks/](tasks/README.md)

## 1. Mục tiêu
Nền tảng thanh toán HiTechPay = Juspay Hyperswitch (white-label) + các cổng thanh toán Việt Nam + SDK/plugin,
triển khai được bằng Docker Compose hoặc Kubernetes, sẵn sàng vận hành thương mại.

## 2. Đã hoàn thành (trên `main`)

| Hạng mục | Kết quả | Kiểm chứng |
|---|---|---|
| Monorepo | 14 repo juspay làm submodule ghim phiên bản; patch series cho 5 repo; script bootstrap/apply/export/upgrade/prepare-docker-context | CI job *Patches apply on pinned upstream* |
| Backend — 7 cổng VN | MoMo, VNPAY, ZaloPay, Viettel Money (beta), payOS, SePay, Pay2S (beta); `vn_utils` (VietQR EMVCo/CRC16, BIN NAPAS, HMAC, GMT+7) | 32 unit test; `cargo check -p router --tests` sạch |
| Lõi Hyperswitch | 2 hook: authorize/psync cục bộ (mặc định tắt) | — |
| Dashboard | Thương hiệu HiTechPay, 7 connector, euclid WASM mới, chuỗi e2e Playwright | `npx rescript` |
| Checkout web SDK | Tiếng Việt, VietQR/MoMo ATM/ngân hàng nội địa, thương hiệu | `npx rescript`, `npm run build` |
| Mobile SDK core | Tiếng Việt, VietQR (màn hình QR mới), MoMo ATM, ngân hàng nội địa, thương hiệu | `rescript build` |
| SDK server | Node.js (`@hitechpay/node`), PHP (`hitechpay/hitechpay-php`) | 26/26 + 19/19 test, CI |
| Plugin | WooCommerce (white-label + sửa lỗi VND ×100, refund/capture), WHMCS | `php -l`, 25/25 test WHMCS |
| Triển khai | Docker Compose, nginx gateway, Helm values, workflow build image GHCR | `docker compose config`, `helm lint/template` |
| Tài liệu | README, kiến trúc, cổng VN, white-label, **hướng dẫn triển khai chi tiết** (`docs/trien-khai/`) | — |

## 3. Trạng thái CI/CD

| Workflow | Trạng thái | Ghi chú |
|---|---|---|
| CI (patches, unit test connector, Node SDK, PHP SDK) | ✅ xanh trên `main` (run #2); các run mới đang chạy | |
| Images (GHCR) | ⚠️ dashboard ✅, checkout-sdk ✅, router ❌ lần đầu | Đã sửa: build với `--features redis-rs` → chờ run mới ([task 002](tasks/002-images-ci-ghcr.md)) |
| CodeQL | ✅ | |

## 4. Việc chưa hoàn thành — thứ tự ưu tiên

| ID | Việc | Ưu tiên | Trạng thái | Người phụ trách |
|---|---|---|---|---|
| [001](tasks/001-default-branch-main.md) | Đặt `main` làm nhánh mặc định + branch protection | P0 | Cần admin repo | Chủ repo |
| [002](tasks/002-images-ci-ghcr.md) | Images CI xanh, GHCR public | P0 | Chờ CI | DevOps |
| [003](tasks/003-connectors-batch2.md) | OnePay, AppotaPay, 9Pay + sửa ZaloPay nửa đêm | P0 | Đang làm (WIP: `wip/connectors-batch2`) | Backend |
| [004](tasks/004-sandbox-e2e-gateways.md) | Kiểm thử sandbox thật từng cổng | P0 | Chưa làm | QA + Backend |
| [007](tasks/007-legal-compliance.md) | Pháp lý (giấy phép NHNN, NĐ 13/2023, PCI DSS) | P0 | Chưa làm | Pháp chế |
| [005](tasks/005-viettel-money-v2.md) | Viettel Money: đối chiếu đặc tả / Partner API v2 | P1 | Chưa làm | Backend |
| [006](tasks/006-pay2s-verify.md) | Pay2S: đối chiếu webhook/API | P1 | Chưa làm | Backend |
| [008](tasks/008-email-branding.md) | Branding e-mail, logo PNG, link hỗ trợ | P1 | Chưa làm | Backend/Design |
| [009](tasks/009-woocommerce-release.md) | WooCommerce: `.mo`, test WordPress thật, release zip | P1 | Chưa làm | Plugin |
| [011](tasks/011-mobile-native.md) | Mobile: build Android/iOS, test thiết bị, wrapper | P1 | Chưa làm | Mobile |
| [013](tasks/013-helm-cluster-validation.md) | Helm trên cluster thật + job CI Helm | P1 | Chưa làm | DevOps |
| [010](tasks/010-whmcs-release.md) | WHMCS: test thật, refund pending | P2 | Chưa làm | Plugin |
| [012](tasks/012-visual-snapshots.md) | Playwright visual snapshots | P2 | Chưa làm | QA |
| [014](tasks/014-docs-site.md) | docs.hitechpay.vn | P2 | Chưa làm | Docs |
| [015](tasks/015-more-gateways.md) | Napas 247, VNPT Pay, ShopeePay, Baokim, NganLuong | P2 | Chưa làm | Backend |
| [016](tasks/016-vn-payouts.md) | Payout VN | P2 | Chưa làm (phụ thuộc 007) | Backend |
| [017](tasks/017-haravan-sapo-plugins.md) | Plugin Haravan/Sapo | P3 | Chưa làm | Plugin |
| [018](tasks/018-e-invoice.md) | Hoá đơn điện tử | P3 | Chưa làm | Backend |
| [019](tasks/019-upstream-core-hooks.md) | Đưa 2 hook lõi lên upstream | P3 | Chưa làm | Backend |

## 5. Lộ trình đề xuất

| Giai đoạn | Nội dung | Điều kiện xong |
|---|---|---|
| **Sprint 1 (1–2 tuần)** | 001, 002, 003, 008, 013 | CI/CD xanh toàn bộ, image public, cài được trên cluster thử |
| **Sprint 2 (2–3 tuần)** | 004, 005, 006, 009, 011 | Mọi cổng VN có báo cáo sandbox; plugin & mobile chạy thật |
| **Go-live** | 007 + checklist [docs/trien-khai/07-checklist-go-live.md](docs/trien-khai/07-checklist-go-live.md) | Ý kiến pháp lý, khoá production, giao dịch thật số tiền nhỏ |
| **Sau go-live** | 010, 012, 014–019 | Theo nhu cầu kinh doanh |

## 6. Quy trình làm việc cho developer

1. `git clone … && scripts/bootstrap.sh --core` (xem [README](README.md)).
2. Nhận một task trong `tasks/`, tạo nhánh `task/NNN-<slug>` từ `main`.
3. Code trong submodule → commit trên nhánh `hitechpay` của submodule → `scripts/export-patches.sh` → commit `patches/` ở repo cha.
4. Kiểm tra: `cargo check -p router --tests`, unit test connector, `npx rescript` (frontend), test SDK.
5. PR vào `main`; CI phải xanh; reviewer duyệt; merge (không force-push `main`).
6. Cập nhật `plan.md` (bảng mục 4) và chuyển file task sang `tasks/done/`.
