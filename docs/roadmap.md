# Lộ trình / Roadmap

## Đã xong (v0.1)
- [x] Monorepo: 14 repo juspay làm submodule ghim phiên bản, patch series, script bootstrap/export/upgrade
- [x] 7 connector VN: MoMo, VNPAY, ZaloPay, Viettel Money (beta), payOS, SePay, Pay2S (beta)
- [x] 2 hook lõi Hyperswitch (authorize / psync cục bộ)
- [x] `vn_utils`: VietQR EMVCo + CRC16, danh bạ BIN NAPAS, HMAC, giờ GMT+7, đối soát mã chuyển khoản
- [x] Dashboard: thương hiệu HiTechPay, 7 connector, WASM cấu hình connector
- [x] Checkout SDK: tiếng Việt, VietQR / MoMo ATM / ngân hàng nội địa, thương hiệu
- [x] SDK server Node.js & PHP (webhook, VietQR, retries)
- [x] Docker compose stack + nginx gateway

## Tiếp theo
- [ ] Chạy test sandbox thật cho từng cổng; bổ sung test vector chính thức của MoMo/VNPAY/ZaloPay
- [ ] Viettel Money: chuyển sang Partner API v2 (RSA) khi có tài liệu; refund API
- [ ] ZaloPay: lưu `app_trans_id`/`m_refund_id` thay vì dựng lại theo ngày
- [ ] Thêm cổng: OnePay, Napas 247 trực tiếp, VNPT Pay, ShopeePay VN, Momo trả góp, AppotaPay, 9Pay, Baokim, NganLuong
- [ ] Payout VN: chuyển khoản Napas 247 (payOS payout, SePay)
- [ ] Mobile SDK (client-core, Android, iOS, React Native): vá thương hiệu + phương thức VN
- [ ] Plugin WooCommerce / WHMCS / Haravan / Sapo cho HiTechPay
- [ ] Hoá đơn điện tử (VAT) tích hợp sau thanh toán
- [ ] Helm values HiTechPay, CI build & publish image
- [ ] Cập nhật Playwright/Cypress e2e theo chuỗi thương hiệu mới
- [ ] Gửi 2 hook lõi upstream cho Juspay
