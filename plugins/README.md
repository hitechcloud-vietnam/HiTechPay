# HiTechPay plugins

Plugin thương mại điện tử / billing cho **HiTechPay** — cổng thanh toán hợp nhất cho doanh nghiệp Việt Nam
(VietQR, MoMo, ZaloPay, VNPAY, Viettel Money, payOS, SePay, Pay2S và thẻ).

E-commerce and billing plugins for **HiTechPay**, the unified payment gateway for Vietnamese businesses.

| Plugin | Nền tảng / Platform | Mã nguồn / Source | Giấy phép / License |
|---|---|---|---|
| HiTechPay for WooCommerce | WordPress 4.0+, WooCommerce 4.0+, PHP 8.0+ | `upstream/hyperswitch-woocommerce-plugin`, nhánh / branch `hitechpay` | GPLv2+ (fork of juspay/hyperswitch-woocommerce-plugin) |
| HiTechPay for WHMCS | WHMCS 8.x, PHP 8.1+ (`curl`, `json`, `mbstring`) | `plugins/whmcs/` | Apache-2.0 |

Môi trường / Environments:

| | API | Checkout SDK |
|---|---|---|
| Sandbox | `https://sandbox-api.hitechpay.vn` | `https://checkout.hitechpay.vn/HyperLoader.js` |
| Production | `https://api.hitechpay.vn` | `https://checkout.hitechpay.vn/HyperLoader.js` |

Khoá cần lấy trên HiTechPay Dashboard / keys from the HiTechPay Dashboard:
**API Key** (secret), **Publishable Key**, **Payment Response Hash Key** (Developers → API Keys) và
**Business Profile ID** (Settings → Business profiles). VND không có đơn vị lẻ — cả hai plugin gửi `150000` cho 150.000 ₫.

---

## 1. HiTechPay for WooCommerce

### Tiếng Việt

Plugin nhúng khung thanh toán HiTechPay vào trang checkout WooCommerce (bản white-label của plugin Hyperswitch).

- Khung thanh toán tiếng Việt mặc định (`vi`, đổi được sang `en` / tự động), bản dịch quản trị tiếng Việt
  (`languages/hitechpay-woocommerce-vi.po`).
- Hoàn tiền, capture thủ công, đồng bộ trạng thái ngay trong trang đơn hàng; webhook HMAC-SHA512 cập nhật đơn.
- Sửa quy đổi tiền: VND (và các tiền tệ không có đơn vị lẻ) không còn bị nhân 100.

**Đóng gói & cài đặt**

```bash
git -C upstream/hyperswitch-woocommerce-plugin archive --format=zip \
    --prefix=hitechpay-woocommerce/ -o ../../hitechpay-woocommerce.zip hitechpay
```

1. Nếu đang dùng plugin *Hyperswitch Checkout for WooCommerce*: tắt và xoá nó trước (hai plugin trùng tên class).
   Cấu hình cũ được giữ nguyên vì gateway id `hyperswitch_checkout` và option không đổi.
2. *Plugins → Add New → Upload Plugin* → chọn `hitechpay-woocommerce.zip` → *Activate*.
3. *WooCommerce → Settings → Payments → HiTechPay*: chọn môi trường, dán API Key, Publishable key,
   Payment Response Hash Key, Business Profile ID.
4. Sao chép Webhook URL hiển thị dưới *Enable Webhook*
   (`https://<shop>/wp-admin/admin-post.php?action=hyperswitch_wc_webhook`) vào trường Webhook URL của business profile.
5. Đặt tiền tệ cửa hàng là **VND** (*WooCommerce → Settings → General*).
6. Bản dịch: WordPress đọc file `.mo`. Biên dịch một lần
   `msgfmt -o languages/hitechpay-woocommerce-vi.mo languages/hitechpay-woocommerce-vi.po` (gói `gettext`) hoặc mở
   file `.po` bằng Poedit rồi lưu.

HiTechPay tự triển khai: điền *Custom API URL* / *Custom Checkout SDK URL* trong cài đặt, hoặc khai báo
`HITECHPAY_API_URL`, `HITECHPAY_SANDBOX_API_URL`, `HITECHPAY_CHECKOUT_SDK_URL` trong `wp-config.php`.

### English

White-label build of the Hyperswitch WooCommerce plugin that embeds the HiTechPay payment sheet at checkout.
Vietnamese payment sheet by default, Vietnamese admin translation, refunds / manual capture / sync from the order
screen, HMAC-SHA512 webhooks, and correct minor-unit handling for VND.

Install: build the zip with the `git archive` command above, remove the upstream Hyperswitch plugin if present
(same class names; settings carry over because the gateway id and option keys are unchanged), upload and activate,
then fill in the keys under *WooCommerce → Settings → Payments → HiTechPay*, register the webhook URL in the business
profile and set the store currency to VND. Compile the `.po` to `.mo` (`msgfmt` or Poedit) for the admin translation.

---

## 2. HiTechPay for WHMCS

### Tiếng Việt

Module cổng thanh toán WHMCS (`hitechpay`) cho hoá đơn:

| Phương thức | Cách hoạt động |
|---|---|
| VietQR | Tạo thanh toán `real_time_payment/viet_qr`, hiển thị mã QR ngay trên hoá đơn (dùng lại trong 15 phút nếu khách tải lại trang) |
| MoMo | `wallet/momo`, chuyển hướng tới MoMo |
| ZaloPay / VNPAY | `bank_redirect/local_bank_redirect`, chuyển hướng tới trang ZaloPay / VNPAY / Viettel Money |
| Thẻ | Tạo *payment link* và chuyển tới trang thanh toán HiTechPay — WHMCS không bao giờ nhận số thẻ |

- Webhook (`modules/gateways/callback/hitechpay.php`): kiểm tra `X-Webhook-Signature-512`
  (HMAC-SHA512 hex của raw body, khoá *Payment Response Hash Key*, so sánh constant-time), `succeeded` →
  `addInvoicePayment` + `logTransaction("Success")`; `failed`/`cancelled`/`expired` → `logTransaction("Failure")`;
  trạng thái trung gian → `Pending`. Chống ghi trùng: tra `GetTransactions` theo `payment_id`, sau đó `checkCbTransID`.
- Khi khách quay lại từ cổng (hoặc bấm "Tôi đã thanh toán"), callback đọc lại giao dịch từ API bằng secret key
  (`force_sync`) — không tin tham số trên URL — rồi chuyển về hoá đơn (`paymentsuccess` / `paymentfailed`).
- Hoàn tiền từ WHMCS: `POST /refunds`. `succeeded` và `pending` (ví/ngân hàng VN hoàn tiền bất đồng bộ) → `success`,
  `failed` → `declined`, lỗi API → `error`.
- Hỗ trợ "Convert To For Processing": hoá đơn ghi nhận theo số tiền gốc của hoá đơn.

**Cài đặt**

1. Sao chép thư mục `plugins/whmcs/modules/` vào thư mục gốc WHMCS (gộp với `modules/` hiện có):
   ```bash
   rsync -a plugins/whmcs/modules/ /var/www/whmcs/modules/
   ```
   Kết quả: `modules/gateways/hitechpay.php`, `modules/gateways/hitechpay/` (helper + `lib/` = SDK PHP),
   `modules/gateways/callback/hitechpay.php`.
2. *System Settings → Payment Gateways → All Payment Gateways* → **HiTechPay**.
3. Điền API Key, Publishable Key, Payment Response Hash Key, (tuỳ chọn) Business Profile ID; bật *Sandbox* khi thử nghiệm;
   tick các phương thức (VietQR, MoMo, ZaloPay / VNPAY, Thẻ) và chọn connector nếu không dùng routing của HiTechPay.
   Không tick phương thức nào → mặc định VietQR.
4. Webhook URL của business profile trên HiTechPay Dashboard:
   `https://<whmcs>/modules/gateways/callback/hitechpay.php`.
5. Tiền tệ hoá đơn nên là VND (hoặc dùng *Convert To For Processing* = VND).

**Kiểm thử**

```bash
php plugins/whmcs/tests/run.php     # mock các hàm WHMCS + HTTP, không cần WHMCS / composer
```

**Cập nhật SDK đi kèm**: `lib/` là bản sao của `sdks/php/src` —
`rsync -a --delete sdks/php/src/ plugins/whmcs/modules/gateways/hitechpay/lib/`.

### English

WHMCS payment gateway module `hitechpay` for invoices: VietQR (QR rendered on the invoice, reused for 15 minutes on
reload), MoMo and ZaloPay / VNPAY redirects, and cards through a hosted HiTechPay payment link (no card data in
WHMCS). The callback verifies `X-Webhook-Signature-512` (hex HMAC-SHA512 of the raw body with the Payment Response
Hash Key, constant-time), records `succeeded` payments with `addInvoicePayment` / `logTransaction`, logs failures and
pending states, and guards against duplicates with a `GetTransactions` lookup plus `checkCbTransID`. Customer returns
are re-verified against the API with the secret key. Refunds use `POST /refunds` (`succeeded`/`pending` → `success`,
`failed` → `declined`, API error → `error`).

Install: copy `plugins/whmcs/modules/` into the WHMCS root, activate **HiTechPay** under
*System Settings → Payment Gateways*, enter the keys, tick the methods, and set the business profile webhook URL to
`https://<whmcs>/modules/gateways/callback/hitechpay.php`. Run `php plugins/whmcs/tests/run.php` for the test suite.

---

## Lưu ý / Notes

- Chưa chạy với WordPress / WHMCS thật hoặc tài khoản sandbox HiTechPay thật; các test dùng hàm mock.
  Not yet run against a live WordPress / WHMCS install or a real HiTechPay sandbox account; tests use mocks.
- WooCommerce: chỉ có `.po`, chưa có `.mo` biên dịch sẵn (môi trường build không có `msgfmt`).
  Only the `.po` ships; compile the `.mo` before relying on the admin translation.
- WHMCS refund `pending` được báo là `success` để WHMCS ghi nhận; theo dõi trạng thái cuối trên HiTechPay Dashboard.
  A `pending` refund is reported to WHMCS as `success`; confirm the final state in the HiTechPay Dashboard.
