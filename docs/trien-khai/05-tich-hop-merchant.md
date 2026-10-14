# 5. Tích hợp cho merchant

HiTechPay dùng chung API với Hyperswitch v1 (tham khảo đầy đủ: https://api-reference.hyperswitch.io —
thay base URL bằng `https://api.<domain>`).

## 5.1 Lấy khoá

Dashboard → **Developers → API Keys**:
- **Secret key** (`snd_…` sandbox / `prd_…` production): chỉ dùng ở server, header `api-key`.
- **Publishable key** (`pk_snd_…`): dùng ở trình duyệt / app với Checkout SDK.

Dashboard → **Developers → Payment Settings**: đặt **Webhook URL** của merchant (nơi HiTechPay báo kết quả) và lấy
**Payment Response Hash Key** để xác thực webhook.

## 5.2 Luồng thanh toán chuẩn

```
Trình duyệt ──(1) đặt hàng──► Server merchant ──(2) POST /payments (secret key)──► HiTechPay
     ▲                               │ ◄────────── client_secret ────────────────────────┘
     │ (3) Checkout SDK + client_secret ──► HiTechPay ──► MoMo/VNPAY/VietQR…
     │ (4) redirect về return_url
Server merchant ◄──(5) webhook payment_succeeded (ký HMAC-SHA512)── HiTechPay
```

### Bước 2 — tạo payment (server)

Node.js (`sdks/node`):

```ts
import { HiTechPay } from "@hitechpay/node";
const htp = new HiTechPay({ apiKey: process.env.HTP_SECRET_KEY!, baseUrl: "https://api.hitechpay.vn" });

const payment = await htp.payments.create({
  amount: 150000, currency: "VND",
  customer_id: "cus_1001",
  description: "Don hang 1001",
  return_url: "https://shop.example.vn/thanh-toan/ket-qua",
  metadata: { order_id: "1001" },
});
// trả payment.client_secret cho trình duyệt
```

PHP (`sdks/php`):

```php
$htp = new \HiTechPay\HiTechPayClient(['api_key' => getenv('HTP_SECRET_KEY'), 'base_url' => 'https://api.hitechpay.vn']);
$payment = $htp->payments->create([
  'amount' => 150000, 'currency' => 'VND', 'description' => 'Don hang 1001',
  'return_url' => 'https://shop.example.vn/thanh-toan/ket-qua',
]);
echo $payment['client_secret'];
```

Lưu ý VND không có phần thập phân: `amount: 150000` = 150.000 ₫.

### Bước 3 — hiển thị form thanh toán (trình duyệt)

```html
<script src="https://checkout.hitechpay.vn/HyperLoader.js"></script>
<div id="unified-checkout"></div>
<button id="pay">Thanh toán</button>
<script>
  const hyper = Hyper("pk_snd_xxx", { customBackendUrl: "https://api.hitechpay.vn" });
  const widgets = hyper.widgets({ clientSecret: "<client_secret>", locale: "vi" });
  const checkout = widgets.create("payment", { layout: "tabs" });
  checkout.mount("#unified-checkout");
  document.getElementById("pay").onclick = async () => {
    const { error } = await hyper.confirmPayment({
      widgets, confirmParams: { return_url: "https://shop.example.vn/thanh-toan/ket-qua" },
    });
    if (error) alert(error.message);
  };
</script>
```

Form tự hiện các phương thức đã bật cho profile: Ví MoMo, MoMo ATM, VietQR (SDK hiển thị mã QR và đếm ngược),
Ngân hàng nội địa (chuyển sang trang VNPAY/ZaloPay/Viettel).

### Không dùng SDK (server-to-server)

Gửi `confirm: true` kèm `payment_method_data` (ví dụ VietQR ở [04 mục 4.4](04-cong-thanh-toan.md#44-kiểm-thử-một-giao-dịch)),
sau đó:
- `next_action.type = redirect_to_url` → chuyển hướng khách tới `next_action.redirect_to_url`.
- `next_action.type = qr_code_information` → hiển thị `image_data_url` (ảnh PNG base64) hoặc `qr_code_url`;
  `display_to_timestamp` là thời điểm hết hạn.

Hằng số dựng sẵn: `vn.vnPaymentMethods` (Node) / `VietNam::vietQr()`, `VietNam::momo()`… (PHP).

### Bước 5 — nhận webhook

Header `X-Webhook-Signature-512` = HMAC-SHA512 (hex) của **raw body**, khoá = Payment Response Hash Key.

```ts
app.post("/hitechpay/webhook", express.raw({ type: "application/json" }), (req, res) => {
  const event = HiTechPay.webhooks.constructEvent(
    req.body, req.header("x-webhook-signature-512")!, process.env.HTP_HASH_KEY!);
  if (event.event_type === "payment_succeeded") { /* đánh dấu đơn đã thanh toán (idempotent) */ }
  res.sendStatus(200);
});
```

```php
$raw = file_get_contents('php://input');
$event = \HiTechPay\Webhook::constructEvent($raw, $_SERVER['HTTP_X_WEBHOOK_SIGNATURE_512'] ?? '', getenv('HTP_HASH_KEY'));
```

Nguyên tắc: xử lý **idempotent** theo `payment_id`, trả 2xx nhanh, đối chiếu lại bằng `GET /payments/{id}` nếu nghi ngờ.

## 5.3 Hoàn tiền

```ts
await htp.refunds.create({ payment_id, amount: 50000, reason: "Khach tra hang" });
```

Hỗ trợ: MoMo, VNPAY, ZaloPay. payOS/SePay/Pay2S/Viettel: hoàn thủ công rồi ghi nhận trên hệ thống của bạn.

## 5.4 Ứng dụng di động

SDK mobile dựa trên `hyperswitch-client-core` (đã vá tiếng Việt, VietQR, MoMo ATM, ngân hàng nội địa):
- React Native: `upstream/react-native-hyperswitch`
- Android: `upstream/hyperswitch-sdk-android`, iOS: `upstream/hyperswitch-sdk-ios`

Khởi tạo với publishable key, `customBackendUrl = https://api.<domain>`, locale `vi`; luồng giống web
(server tạo payment → app dùng `client_secret`).

## 5.5 Plugin

| Nền tảng | Vị trí | Hướng dẫn |
|---|---|---|
| WooCommerce | patch `patches/hyperswitch-woocommerce-plugin` (đóng gói từ `upstream/hyperswitch-woocommerce-plugin`) | [plugins/README.md](../../plugins/README.md) |
| WHMCS | `plugins/whmcs/` | [plugins/README.md](../../plugins/README.md) |

## 5.6 Môi trường

| | Sandbox | Production |
|---|---|---|
| API | `https://sandbox-api.<domain>` (hoặc cùng API với connector *Test mode*) | `https://api.<domain>` |
| Khoá | `snd_…`, `pk_snd_…` | `prd_…`, `pk_prd_…` |
| Cổng thanh toán | khoá sandbox, Test mode bật | khoá production, Test mode tắt |
