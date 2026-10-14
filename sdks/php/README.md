# hitechpay/hitechpay-php

SDK PHP chính thức (phía máy chủ) cho **HiTechPay** — cổng thanh toán tương thích API Hyperswitch, kèm tiện ích cho Việt Nam (VietQR, MoMo, định dạng VND).

Official PHP server-side SDK for **HiTechPay** — a Hyperswitch-compatible payments API, with Vietnam helpers (VietQR, MoMo, VND formatting).

- PHP >= 8.1, không có dependency / no runtime dependencies (chỉ cần / only `ext-curl`, `ext-json`)
- Tự động thử lại / automatic retries (429, 5xx, network errors — idempotent requests only, exponential backoff)
- Exception có kiểu / typed exceptions
- Xác thực webhook / webhook verification (HMAC-SHA512 / HMAC-SHA256, `hash_equals`)
- Tạo mã VietQR NAPAS / NAPAS VietQR payload builder (CRC16-CCITT-FALSE)

> API tương thích / wire-compatible with <https://api-reference.hyperswitch.io> (v1).

---

## Cài đặt / Install

```bash
composer require hitechpay/hitechpay-php
```

## Khởi tạo / Setup

```php
use HiTechPay\HiTechPayClient;

$hitechpay = new HiTechPayClient([
    'api_key'     => getenv('HITECHPAY_SECRET_KEY'),     // header `api-key`
    'base_url'    => HiTechPayClient::SANDBOX_BASE_URL,  // mặc định / default https://api.hitechpay.vn
    'timeout_ms'  => 30000,
    'max_retries' => 2,                                  // GET/DELETE only
]);
```

| Môi trường / Environment | Base URL |
| --- | --- |
| Production | `https://api.hitechpay.vn` |
| Sandbox | `https://sandbox-api.hitechpay.vn` |

> **VND không có đơn vị lẻ** — `'amount' => 150000` là 150.000 ₫. / **VND is zero-decimal** — `150000` means 150,000 VND.

Mọi phương thức trả về mảng (JSON đã decode). / All methods return decoded JSON arrays.

---

## Ví dụ / Examples

### Tạo thanh toán VietQR (SePay / payOS) — Create a VietQR payment

```php
use HiTechPay\VietNam;

$payment = $hitechpay->payments->create([
    'amount'      => 150000,
    'currency'    => 'VND',
    'confirm'     => true,
    'connector'   => ['sepay'],          // hoặc / or ['payos']
    'description' => 'Don hang #1001',
    'return_url'  => 'https://shop.example.vn/checkout/return',
    'metadata'    => ['order_id' => '1001'],
] + VietNam::vietQr());

if (($payment['next_action']['type'] ?? null) === 'qr_code_information') {
    $qrUrl = $payment['next_action']['qr_code_url'] ?? $payment['next_action']['image_data_url'];
    echo 'Quét để trả ' . VietNam::formatVnd($payment['amount']) . ": {$qrUrl}";
}
```

### Ví MoMo — MoMo wallet redirect

```php
$payment = $hitechpay->payments->create([
    'amount'     => 99000,
    'currency'   => 'VND',
    'confirm'    => true,
    'connector'  => ['momo'],
    'return_url' => 'https://shop.example.vn/checkout/return',
] + VietNam::momo());

if (($payment['next_action']['type'] ?? null) === 'redirect_to_url') {
    header('Location: ' . $payment['next_action']['redirect_to_url'], true, 303);
    exit;
}
```

| Preset | payment_method / payment_method_type | payment_method_data |
| --- | --- | --- |
| `VietNam::momo()` | `wallet` / `momo` | `{"wallet":{"momo_redirect":{}}}` |
| `VietNam::momoAtm()` | `card_redirect` / `momo_atm` | `{"card_redirect":{"momo_atm":{}}}` |
| `VietNam::vietQr()` | `real_time_payment` / `viet_qr` | `{"real_time_payment":{"viet_qr":{}}}` |
| `VietNam::localBankRedirect()` | `bank_redirect` / `local_bank_redirect` | `{"bank_redirect":{"local_bank_redirect":{}}}` |

### Hoàn tiền, khách hàng — Refunds, customers

```php
$hitechpay->payments->retrieve('pay_xxx', ['force_sync' => true]);
$hitechpay->payments->capture('pay_xxx', ['amount_to_capture' => 150000]);
$hitechpay->payments->cancel('pay_xxx', ['cancellation_reason' => 'requested_by_customer']);
$hitechpay->payments->list(['limit' => 20]);

$refund = $hitechpay->refunds->create(['payment_id' => 'pay_xxx', 'amount' => 50000, 'reason' => 'Khach tra hang']);
$hitechpay->refunds->retrieve($refund['refund_id']);
$hitechpay->refunds->list(['payment_id' => 'pay_xxx']);

$customer = $hitechpay->customers->create(['name' => 'Nguyễn Văn A', 'email' => 'a@example.vn']);
$hitechpay->customers->update($customer['customer_id'], ['phone' => '901234567', 'phone_country_code' => '+84']);
$hitechpay->paymentMethods->list($customer['customer_id']);
$hitechpay->customers->delete($customer['customer_id']);

$hitechpay->paymentLinks->retrieve('plink_xxx');
$hitechpay->mandates->revoke('man_xxx');
$hitechpay->disputes->list(['limit' => 10]);

// Endpoint khác / any other endpoint:
$hitechpay->request('POST', '/payment_link/list', ['limit' => 10]);
```

### Tự tạo mã VietQR — Build a NAPAS VietQR string

```php
$qr = VietNam::buildVietQrPayload(
    bankBin: '970422',            // MB Bank
    accountNumber: '0123456789',
    amount: 150000,               // null => QR tĩnh / static QR
    memo: 'Thanh toán ĐH 1001',   // bỏ dấu, tối đa 25 ký tự / diacritics stripped, max 25 chars
);
// "000201010212385400...6304XXXX" — render bằng thư viện QR bất kỳ / with any QR library
```

---

## Laravel

`config/services.php`:

```php
'hitechpay' => [
    'secret'   => env('HITECHPAY_SECRET_KEY'),
    'base_url' => env('HITECHPAY_BASE_URL', 'https://api.hitechpay.vn'),
    'webhook_hash_key' => env('HITECHPAY_WEBHOOK_HASH_KEY'),
],
```

`app/Providers/AppServiceProvider.php`:

```php
use HiTechPay\HiTechPayClient;

public function register(): void
{
    $this->app->singleton(HiTechPayClient::class, fn () => new HiTechPayClient([
        'api_key'  => config('services.hitechpay.secret'),
        'base_url' => config('services.hitechpay.base_url'),
    ]));
}
```

`app/Http/Controllers/CheckoutController.php`:

```php
use App\Models\Order;
use HiTechPay\Exception\HiTechPayException;
use HiTechPay\HiTechPayClient;
use HiTechPay\VietNam;

class CheckoutController extends Controller
{
    public function pay(Order $order, HiTechPayClient $hitechpay)
    {
        try {
            $payment = $hitechpay->payments->create([
                'amount'     => $order->total,            // VND
                'currency'   => 'VND',
                'confirm'    => true,
                'connector'  => ['momo'],
                'return_url' => route('checkout.return', $order),
                'metadata'   => ['order_id' => (string) $order->id],
            ] + VietNam::momo());
        } catch (HiTechPayException $e) {
            report($e);
            return back()->withErrors(['payment' => $e->getMessage()]);
        }

        $order->update(['payment_id' => $payment['payment_id']]);

        return ($payment['next_action']['type'] ?? null) === 'redirect_to_url'
            ? redirect()->away($payment['next_action']['redirect_to_url'])
            : redirect()->route('checkout.return', $order);
    }
}
```

Webhook — `routes/api.php` (route trong `api` không có CSRF / no CSRF on api routes):

```php
use HiTechPay\Exception\SignatureVerificationException;
use HiTechPay\Webhook;
use Illuminate\Http\Request;

Route::post('/webhooks/hitechpay', function (Request $request) {
    try {
        $event = Webhook::constructEvent(
            $request->getContent(),                              // raw body
            $request->header('x-webhook-signature-512') ?? $request->header('x-webhook-signature-256'),
            config('services.hitechpay.webhook_hash_key'),       // Payment Response Hash Key
        );
    } catch (SignatureVerificationException) {
        abort(400);
    }

    if ($event['event_type'] === 'payment_succeeded') {
        $payment = $event['content']['object'];
        Order::where('payment_id', $payment['payment_id'])->update(['status' => 'paid']);
    }

    return response()->noContent();
});
```

---

## WooCommerce (kiểu plugin cổng thanh toán / payment-gateway style)

Rút gọn — dùng trong plugin có `composer` autoload. Condensed; assumes the plugin loads Composer's autoloader.

```php
use HiTechPay\HiTechPayClient;
use HiTechPay\VietNam;
use HiTechPay\Webhook;

class WC_Gateway_HiTechPay_VietQR extends WC_Payment_Gateway
{
    public function __construct()
    {
        $this->id                 = 'hitechpay_vietqr';
        $this->method_title       = 'HiTechPay VietQR';
        $this->method_description = 'Chuyển khoản VietQR qua HiTechPay';
        $this->has_fields         = false;
        $this->init_form_fields();
        $this->init_settings();
        $this->title = $this->get_option('title', 'Chuyển khoản VietQR');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        add_action('woocommerce_api_hitechpay', [$this, 'handle_webhook']); // /?wc-api=hitechpay
    }

    public function init_form_fields(): void
    {
        $this->form_fields = [
            'enabled'    => ['title' => 'Bật / Enable', 'type' => 'checkbox', 'default' => 'no'],
            'title'      => ['title' => 'Tiêu đề / Title', 'type' => 'text', 'default' => 'Chuyển khoản VietQR'],
            'secret_key' => ['title' => 'Secret API key', 'type' => 'password'],
            'hash_key'   => ['title' => 'Payment Response Hash Key', 'type' => 'password'],
            'sandbox'    => ['title' => 'Sandbox', 'type' => 'checkbox', 'default' => 'yes'],
        ];
    }

    private function client(): HiTechPayClient
    {
        return new HiTechPayClient([
            'api_key'  => $this->get_option('secret_key'),
            'base_url' => $this->get_option('sandbox') === 'yes'
                ? HiTechPayClient::SANDBOX_BASE_URL
                : HiTechPayClient::DEFAULT_BASE_URL,
        ]);
    }

    public function process_payment($order_id): array
    {
        $order = wc_get_order($order_id);
        try {
            $payment = $this->client()->payments->create([
                'amount'      => (int) round((float) $order->get_total()),   // VND
                'currency'    => 'VND',
                'confirm'     => true,
                'connector'   => ['sepay'],
                'description' => 'WC #' . $order->get_order_number(),
                'return_url'  => $this->get_return_url($order),
                'email'       => $order->get_billing_email(),
                'metadata'    => ['wc_order_id' => (string) $order_id],
            ] + VietNam::vietQr());
        } catch (\HiTechPay\Exception\HiTechPayException $e) {
            wc_add_notice('Thanh toán lỗi: ' . $e->getMessage(), 'error');
            return ['result' => 'failure'];
        }

        $order->set_transaction_id($payment['payment_id']);
        $order->update_status('on-hold', 'Chờ khách quét VietQR');
        $order->update_meta_data('_hitechpay_next_action', wp_json_encode($payment['next_action'] ?? null));
        $order->save();

        // Trang "thank you" hiển thị QR từ next_action.qr_code_url / show the QR on the thank-you page.
        return ['result' => 'success', 'redirect' => $this->get_return_url($order)];
    }

    public function process_refund($order_id, $amount = null, $reason = ''): bool
    {
        $order = wc_get_order($order_id);
        $this->client()->refunds->create([
            'payment_id' => $order->get_transaction_id(),
            'amount'     => (int) round((float) $amount),
            'reason'     => $reason ?: 'WooCommerce refund',
        ]);
        return true;
    }

    public function handle_webhook(): void
    {
        $raw = file_get_contents('php://input') ?: '';
        if (!Webhook::verify($raw, Webhook::signatureFromGlobals(), $this->get_option('hash_key'))) {
            status_header(400);
            exit;
        }
        $event   = json_decode($raw, true);
        $payment = $event['content']['object'] ?? [];
        $orderId = (int) ($payment['metadata']['wc_order_id'] ?? 0);
        if ($orderId && ($order = wc_get_order($orderId))) {
            match ($event['event_type']) {
                'payment_succeeded' => $order->payment_complete($payment['payment_id']),
                'payment_failed'    => $order->update_status('failed', 'HiTechPay: payment failed'),
                default             => null,
            };
        }
        status_header(200);
        exit;
    }
}
```

Webhook URL cấu hình trên dashboard / configure in dashboard: `https://shop.example.vn/?wc-api=hitechpay`.

---

## Exception

Tất cả kế thừa / all extend `HiTechPay\Exception\HiTechPayException` (`getStatus()`, `getErrorCode()`, `getErrorType()`, `getMessage()`, `getRaw()`, `getRequestId()`).

| Class | Khi nào / When |
| --- | --- |
| `InvalidRequestException` | 400, 422 |
| `AuthenticationException` | 401, 403 |
| `NotFoundException` | 404 |
| `ConflictException` | 409 |
| `RateLimitException` | 429 |
| `ApiErrorException` | 5xx |
| `ConnectionException` | lỗi mạng / timeout (status 0) |
| `SignatureVerificationException` | chữ ký webhook sai / bad webhook signature |

## Thử lại / Retries

GET/DELETE (và `refunds->list`) được thử lại khi 429/5xx/lỗi mạng (backoff luỹ thừa + jitter, tôn trọng `Retry-After`). POST tạo mới **không** tự thử lại; nếu bạn đặt `payment_id` cố định có thể bật `['idempotent' => true]` ở tham số `$options`.

GET/DELETE (and `refunds->list`) are retried on 429/5xx/network errors (jittered exponential backoff, honours `Retry-After`). Creating POSTs are **not** retried; pass `['idempotent' => true]` as `$options` if you supply a fixed `payment_id`.

## Kiểm thử / Tests

```bash
php tests/run.php
```

License: Apache-2.0
