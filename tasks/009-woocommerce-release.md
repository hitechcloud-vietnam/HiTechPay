# 009 — WooCommerce: file .mo + kiểm thử WordPress thật

Trạng thái: Chưa làm
Ưu tiên: P1

## Bối cảnh
Plugin white-label nằm ở patch `patches/hyperswitch-woocommerce-plugin/` (submodule `upstream/hyperswitch-woocommerce-plugin`).
Đã có `languages/hitechpay-woocommerce-vi.po` nhưng **chưa có `.mo`** (máy build thiếu `msgfmt`). Chưa thử trên WordPress thật.

## Các bước
1. `sudo apt-get install gettext && msgfmt -o languages/hitechpay-woocommerce-vi.mo languages/hitechpay-woocommerce-vi.po`
   trong submodule (nhánh `hitechpay`) → commit → `scripts/export-patches.sh`.
2. Dựng WordPress + WooCommerce (docker `wordpress` + `mariadb`), cài plugin (zip theo [plugins/README.md](../plugins/README.md)).
3. Kiểm thử: VietQR, MoMo, hoàn tiền từ admin Woo, webhook, đổi ngôn ngữ vi/en, đơn VND (không nhân 100).
4. Thêm workflow CI: `php -l` toàn bộ file plugin; đóng gói zip làm artifact.

## Tiêu chí hoàn thành
- Đơn hàng thật end-to-end trên WooCommerce với HiTechPay sandbox; zip release đính kèm GitHub Release.
