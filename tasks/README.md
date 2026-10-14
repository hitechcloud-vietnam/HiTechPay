# Việc chưa hoàn thành / Open work items

Mỗi file `NNN-*.md` là một việc độc lập, đủ bối cảnh để một developer khác nhận và làm tiếp.
Tổng quan & thứ tự ưu tiên: [../plan.md](../plan.md).

Quy ước:
- **Nhận việc**: sửa dòng `Trạng thái:` thành `Đang làm — <tên/GitHub>` và mở nhánh `task/NNN-<slug>`.
- **Xong**: đáp ứng toàn bộ *Tiêu chí hoàn thành*, CI xanh, merge vào `main`, chuyển file sang `tasks/done/`.
- Code trong submodule: làm trên nhánh `hitechpay` của submodule → `scripts/export-patches.sh` → commit `patches/`
  (xem [docs/architecture.md](../docs/architecture.md)). Không bao giờ commit con trỏ submodule trỏ tới commit local.

| ID | Việc | Ưu tiên | Loại |
|---|---|---|---|
| 001 | [Đặt `main` làm nhánh mặc định](001-default-branch-main.md) | P0 | Quản trị repo |
| 002 | [Images CI xanh & GHCR public](002-images-ci-ghcr.md) | P0 | CI/CD |
| 003 | [Connector đợt 2: OnePay, AppotaPay, 9Pay + sửa ZaloPay](003-connectors-batch2.md) | P0 | Backend |
| 004 | [Kiểm thử sandbox thật từng cổng VN](004-sandbox-e2e-gateways.md) | P0 | QA |
| 005 | [Viettel Money: đối chiếu đặc tả + Partner API v2](005-viettel-money-v2.md) | P1 | Backend |
| 006 | [Pay2S: đối chiếu định dạng webhook & API](006-pay2s-verify.md) | P1 | Backend |
| 007 | [Pháp lý & tuân thủ trước go-live](007-legal-compliance.md) | P0 | Pháp chế |
| 008 | [Branding e-mail & nội dung tuân thủ](008-email-branding.md) | P1 | Backend/Design |
| 009 | [WooCommerce: file .mo + kiểm thử WordPress thật](009-woocommerce-release.md) | P1 | Plugin |
| 010 | [WHMCS: kiểm thử thật, trạng thái hoàn tiền pending](010-whmcs-release.md) | P2 | Plugin |
| 011 | [Mobile: build Android/iOS, test thiết bị, wrapper branding](011-mobile-native.md) | P1 | Mobile |
| 012 | [Playwright visual snapshots theo thương hiệu mới](012-visual-snapshots.md) | P2 | QA |
| 013 | [Helm: cài trên cluster thật, chart published vs local](013-helm-cluster-validation.md) | P1 | DevOps |
| 014 | [Trang tài liệu docs.hitechpay.vn](014-docs-site.md) | P2 | Docs |
| 015 | [Cổng mới: Napas 247, VNPT Pay, ShopeePay, Baokim, NganLuong](015-more-gateways.md) | P2 | Backend |
| 016 | [Payout VN (chi hộ)](016-vn-payouts.md) | P2 | Backend |
| 017 | [Plugin Haravan / Sapo](017-haravan-sapo-plugins.md) | P3 | Plugin |
| 018 | [Hoá đơn điện tử sau thanh toán](018-e-invoice.md) | P3 | Backend |
| 019 | [Đóng góp 2 hook lõi lên upstream Juspay](019-upstream-core-hooks.md) | P3 | Cộng đồng |
