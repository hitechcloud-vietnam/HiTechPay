# @hitechpay/node

SDK Node.js chính thức (phía máy chủ) cho **HiTechPay** — cổng thanh toán tương thích API Hyperswitch, kèm tiện ích riêng cho Việt Nam (VietQR, MoMo, định dạng VND).

Official Node.js server-side SDK for **HiTechPay** — a Hyperswitch-compatible payments API, with Vietnam helpers (VietQR, MoMo, VND formatting).

- Không có dependency khi chạy / zero runtime dependencies (global `fetch`, `node:crypto`)
- Node.js >= 18, TypeScript types included
- Tự động thử lại / automatic retries (429, 5xx, network errors — idempotent requests only, exponential backoff)
- Xác thực webhook / webhook verification (HMAC-SHA512 / HMAC-SHA256)

> Tài liệu API đầy đủ / full API reference: the HiTechPay API is wire-compatible with
> <https://api-reference.hyperswitch.io> (v1 endpoints).

---

## Cài đặt / Install

```bash
npm install @hitechpay/node
```

## Khởi tạo / Setup

```ts
import { HiTechPay } from '@hitechpay/node';
// CommonJS: const { HiTechPay } = require('@hitechpay/node');

const hitechpay = new HiTechPay({
  apiKey: process.env.HITECHPAY_SECRET_KEY!, // gửi qua header `api-key` / sent as `api-key` header
  baseUrl: HiTechPay.SANDBOX_BASE_URL,      // mặc định / default: https://api.hitechpay.vn
  timeoutMs: 30_000,                        // mặc định / default 30s per attempt
  maxRetries: 2,                            // mặc định / default 2 (GET/DELETE only)
});
```

| Môi trường / Environment | Base URL |
| --- | --- |
| Production | `https://api.hitechpay.vn` (`HiTechPay.DEFAULT_BASE_URL`) |
| Sandbox | `https://sandbox-api.hitechpay.vn` (`HiTechPay.SANDBOX_BASE_URL`) |

> **VND không có đơn vị lẻ.** `amount: 150000` nghĩa là 150.000 ₫.
> **VND is a zero-decimal currency.** `amount: 150000` means 150,000 VND.

---

## Ví dụ / Examples

### 1. Thanh toán VietQR qua SePay / payOS — VietQR payment via SePay / payOS

```ts
import { HiTechPay, vnPaymentMethods, formatVnd } from '@hitechpay/node';

const payment = await hitechpay.payments.create({
  amount: 150000,
  currency: 'VND',
  confirm: true,
  connector: ['sepay'],            // hoặc / or ['payos']
  ...vnPaymentMethods.vietQr,      // real_time_payment / viet_qr
  customer_id: 'cus_123',
  description: 'Don hang #1001',
  return_url: 'https://shop.example.vn/checkout/return',
  metadata: { order_id: '1001' },
});

if (payment.next_action?.type === 'qr_code_information') {
  // Hiển thị ảnh QR cho khách / show the QR to the shopper
  const { image_data_url, qr_code_url } = payment.next_action as {
    image_data_url?: string; qr_code_url?: string;
  };
  console.log(`Quét mã để trả ${formatVnd(payment.amount)}`, qr_code_url ?? image_data_url);
}
// Trạng thái cuối cùng đến qua webhook (payment_succeeded) /
// the final status arrives via webhook (payment_succeeded).
```

### 2. Ví MoMo (chuyển hướng) — MoMo wallet redirect

```ts
import { vnPaymentMethods } from '@hitechpay/node';

const payment = await hitechpay.payments.create({
  amount: 99000,
  currency: 'VND',
  confirm: true,
  connector: ['momo'],
  ...vnPaymentMethods.momo,        // wallet / momo  -> { wallet: { momo_redirect: {} } }
  return_url: 'https://shop.example.vn/checkout/return',
});

if (payment.status === 'requires_customer_action' && payment.next_action?.type === 'redirect_to_url') {
  res.redirect(303, (payment.next_action as { redirect_to_url: string }).redirect_to_url);
}
```

Các preset khác / other presets:

| Preset | payment_method / payment_method_type | payment_method_data |
| --- | --- | --- |
| `vnPaymentMethods.momo` | `wallet` / `momo` | `{ wallet: { momo_redirect: {} } }` |
| `vnPaymentMethods.momoAtm` | `card_redirect` / `momo_atm` | `{ card_redirect: { momo_atm: {} } }` |
| `vnPaymentMethods.vietQr` | `real_time_payment` / `viet_qr` | `{ real_time_payment: { viet_qr: {} } }` |
| `vnPaymentMethods.localBankRedirect` | `bank_redirect` / `local_bank_redirect` | `{ bank_redirect: { local_bank_redirect: {} } }` |

### 3. Xử lý webhook với Express — Handle webhooks in Express

Webhook được ký bằng HMAC-SHA512 (hex) của **raw body** trong header `x-webhook-signature-512`, khoá là *Payment Response Hash Key* của profile trên dashboard.
Webhooks are signed with HMAC-SHA512 (hex) of the **raw body** in `x-webhook-signature-512`, keyed with the profile's *Payment Response Hash Key*. `x-webhook-signature-256` (HMAC-SHA256) is also supported.

```ts
import express from 'express';
import { HiTechPay, HiTechPaySignatureVerificationError } from '@hitechpay/node';

const app = express();

// QUAN TRỌNG: dùng express.raw — không parse JSON trước khi xác thực.
// IMPORTANT: use express.raw — do not JSON-parse before verifying.
app.post('/webhooks/hitechpay', express.raw({ type: 'application/json' }), (req, res) => {
  try {
    const event = HiTechPay.webhooks.constructEvent(
      req.body,                                    // Buffer
      req.headers,                                 // hoặc / or req.header('x-webhook-signature-512')
      process.env.HITECHPAY_WEBHOOK_HASH_KEY!,
    );

    switch (event.event_type) {
      case 'payment_succeeded': {
        const p = event.content.object as { payment_id: string; amount: number };
        // TODO: đánh dấu đơn hàng đã thanh toán (idempotent theo event_id)
        // TODO: mark order paid (dedupe on event.event_id)
        break;
      }
      case 'payment_failed':
      case 'refund_succeeded':
        break;
    }
    res.sendStatus(200);
  } catch (err) {
    if (err instanceof HiTechPaySignatureVerificationError) return res.sendStatus(400);
    throw err;
  }
});
```

Chỉ cần kiểm tra true/false / just a boolean check:

```ts
const ok = HiTechPay.webhooks.verify(rawBody, signatureHeader, paymentResponseHashKey);
```

### 4. Tự tạo mã VietQR (NAPAS) — Build a NAPAS VietQR string yourself

Hữu ích cho QR tĩnh tại quầy hoặc in hoá đơn. Useful for static counter QR codes or printed invoices.

```ts
import { buildVietQrPayload } from '@hitechpay/node';

const qr = buildVietQrPayload({
  bankBin: '970422',          // MB Bank
  accountNumber: '0123456789',
  amount: 150000,             // bỏ trống => QR tĩnh / omit => static QR
  memo: 'Thanh toán ĐH 1001', // dấu tiếng Việt được bỏ / diacritics are stripped (max 25)
});
// => "000201010212385400...6304XXXX" — render with any QR library
```

Chuẩn EMVCo / NAPAS: `00=01`, `01=12|11`, `38={00=A000000727, 01={00=BIN, 01=account}, 02=QRIBFTTA}`, `53=704`, `54=amount`, `58=VN`, `62={08=memo}`, `63=CRC16-CCITT-FALSE`.

### 5. Hoàn tiền, khách hàng, ... — Refunds, customers, etc.

```ts
await hitechpay.payments.retrieve('pay_xxx', { force_sync: true });
await hitechpay.payments.capture('pay_xxx', { amount_to_capture: 150000 });
await hitechpay.payments.cancel('pay_xxx', { cancellation_reason: 'requested_by_customer' });
await hitechpay.payments.list({ limit: 20 });

const refund = await hitechpay.refunds.create({ payment_id: 'pay_xxx', amount: 50000, reason: 'Khach tra hang' });
await hitechpay.refunds.retrieve(refund.refund_id);
await hitechpay.refunds.list({ payment_id: 'pay_xxx' });

const customer = await hitechpay.customers.create({ name: 'Nguyễn Văn A', email: 'a@example.vn', phone: '901234567', phone_country_code: '+84' });
await hitechpay.paymentMethods.list(customer.customer_id);
await hitechpay.customers.delete(customer.customer_id);

await hitechpay.paymentLinks.retrieve('plink_xxx');
await hitechpay.mandates.revoke('man_xxx');
await hitechpay.disputes.list({ limit: 10 });

// Endpoint chưa được bọc / any other endpoint:
await hitechpay.request('POST', '/payment_link/list', { body: { limit: 10 } });
```

Định dạng tiền / currency formatting:

```ts
import { formatVnd } from '@hitechpay/node';
formatVnd(150000); // "150.000 ₫"
```

---

## Lỗi / Errors

Mọi lỗi đều là `HiTechPayError` (có `status`, `code`, `type`, `message`, `raw`, `requestId`).
All errors extend `HiTechPayError` with `status`, `code`, `type`, `message`, `raw` (response body), `requestId`.

| Class | Khi nào / When |
| --- | --- |
| `HiTechPayInvalidRequestError` | 400, 422 |
| `HiTechPayAuthenticationError` | 401, 403 |
| `HiTechPayNotFoundError` | 404 |
| `HiTechPayConflictError` | 409 |
| `HiTechPayRateLimitError` | 429 |
| `HiTechPayAPIError` | 5xx |
| `HiTechPayConnectionError` | network error / timeout (`status === 0`) |
| `HiTechPaySignatureVerificationError` | invalid webhook signature |

```ts
import { HiTechPayError } from '@hitechpay/node';
try {
  await hitechpay.payments.create({ amount: 1000, currency: 'VND' });
} catch (e) {
  if (e instanceof HiTechPayError) console.error(e.status, e.code, e.message, e.raw);
}
```

## Thử lại / Retries

- GET / DELETE (và / and `refunds.list`) được thử lại khi gặp 429, 5xx hoặc lỗi mạng, backoff luỹ thừa có jitter, tôn trọng `Retry-After`.
- GET / DELETE (and `refunds.list`) are retried on 429, 5xx and network errors with jittered exponential backoff, honouring `Retry-After`.
- POST tạo mới **không** tự thử lại để tránh trừ tiền hai lần. Nếu bạn tự đặt `payment_id`/`refund_id` cố định, có thể bật: `{ idempotent: true }`.
- Creating POSTs are **not** retried, to avoid double charges. If you pass a fixed `payment_id` / `refund_id`, opt in per call: `hitechpay.payments.create(params, { idempotent: true })`.

## Phát triển / Development

```bash
npm install
npm test        # tsc + node:test
npm run build   # -> dist/
```

License: Apache-2.0
