# 4. Cấu hình cổng thanh toán Việt Nam

Chi tiết kỹ thuật (thuật toán ký, mã trạng thái, giới hạn) xem [../connectors-vn.md](../connectors-vn.md).
Tài liệu này là quy trình thao tác cho người vận hành.

## 4.1 Quy trình chung (mọi cổng)

1. Đăng nhập `https://dashboard.<domain>` → chọn merchant/profile.
2. **Connectors → Payment Processors → + Connect** → chọn cổng (MoMo, VNPAY, …).
3. Nhập khoá theo bảng ánh xạ ở 4.2. Bật **Test mode** khi dùng khoá sandbox.
4. Chọn phương thức thanh toán (Payment methods) muốn bật, tiền tệ **VND**, quốc gia **VN**,
   giới hạn số tiền (ví dụ MoMo: 1.000 – 50.000.000 ₫).
5. Lưu. Dashboard hiển thị **Webhook URL** dạng
   `https://api.<domain>/webhooks/<merchant_id>/<merchant_connector_id>` — sao chép.
6. Khai báo webhook/IPN URL đó tại trang quản trị của cổng (nếu cổng yêu cầu — xem từng cổng bên dưới).
7. Tạo một giao dịch thử (mục 4.4) và kiểm tra trạng thái chuyển sang `succeeded`.

Tạo connector bằng API (thay cho dashboard, hữu ích khi tự động hoá):

```bash
curl -X POST "https://api.<domain>/account/<merchant_id>/connectors" \
  -H "Content-Type: application/json" -H "api-key: <ADMIN_API_KEY>" \
  -d '{
    "connector_type": "payment_processor",
    "connector_name": "momo",
    "profile_id": "<profile_id>",
    "test_mode": true,
    "connector_account_details": {
      "auth_type": "SignatureKey",
      "api_key": "<Partner Code>", "key1": "<Access Key>", "api_secret": "<Secret Key>"
    },
    "payment_methods_enabled": [
      { "payment_method": "wallet",
        "payment_method_types": [ { "payment_method_type": "momo", "minimum_amount": 1000,
          "maximum_amount": 50000000, "recurring_enabled": false, "installment_payment_enabled": false } ] },
      { "payment_method": "card_redirect",
        "payment_method_types": [ { "payment_method_type": "momo_atm", "minimum_amount": 10000,
          "maximum_amount": 50000000, "recurring_enabled": false, "installment_payment_enabled": false } ] }
    ]
  }'
```

## 4.2 Bảng ánh xạ khoá

| Cổng (`connector_name`) | Auth type | API Key | Key1 | API Secret | Webhook secret (Merchant secret) | Phương thức |
|---|---|---|---|---|---|---|
| `momo` | SignatureKey | Partner Code | Access Key | Secret Key | — | wallet/`momo`, card_redirect/`momo_atm` |
| `vnpay` | BodyKey | vnp_TmnCode | vnp_HashSecret | — | — | real_time_payment/`viet_qr`, bank_redirect/`local_bank_redirect` |
| `zalopay` | SignatureKey | app_id | key1 | key2 | — | `viet_qr`, `local_bank_redirect` |
| `viettelmoney` | SignatureKey | merchant_code | access_code | hash_key | — | `local_bank_redirect`, `viet_qr` |
| `payos` | SignatureKey | Client ID | API Key | Checksum Key | — | `viet_qr` |
| `sepay` | SignatureKey | API Token | Số tài khoản nhận | Mã ngân hàng (MB, VCB, TCB… hoặc BIN 6 số) | **API key webhook** | `viet_qr` |
| `pay2s` | SignatureKey | API Token | Số tài khoản nhận | Mã ngân hàng | **Token webhook** | `viet_qr` |

Metadata tuỳ chọn cho VNPAY / Viettel Money (ô *Metadata* JSON của connector):

```json
{ "environment": "production" }                       // hoặc "sandbox"
{ "payment_url": "https://pay.vnpay.vn/vpcpay.html" }  // ép URL trang thanh toán cụ thể
```

Không đặt metadata thì hệ thống dùng trang **sandbox** khi connector bật *Test mode*, trang **production** khi tắt.

## 4.3 Từng cổng

### MoMo
- Sandbox: endpoint `https://test-payment.momo.vn/` (mặc định khi `HTP_RUN_ENV=sandbox`). Thanh toán thử bằng
  ứng dụng *MoMo Test* (MoMo cung cấp khi cấp tài khoản sandbox).
- IPN: HiTechPay tự gửi `ipnUrl` = Webhook URL trong mỗi giao dịch — **không cần** khai báo ở trang MoMo.
- Hoàn tiền: thao tác *Refund* trên dashboard / API; MoMo chỉ hoàn sau khi giao dịch đã thành công (có `transId`).
- Production: đổi base URL sang `https://payment.momo.vn/`, khoá production, tắt Test mode.

### VNPAY
- Sandbox: trang `https://sandbox.vnpayment.vn/paymentv2/vpcpay.html`, API `…/merchant_webapi/api/transaction`.
  Thẻ test nội địa do VNPAY công bố trong tài liệu sandbox (ngân hàng NCB, OTP thử nghiệm).
- **IPN URL**: đăng nhập trang quản trị merchant VNPAY (sandbox: `sandbox.vnpayment.vn/merchantv2`) → *Cấu hình IPN URL*
  → dán Webhook URL. VNPAY gọi IPN bằng **GET**; HiTechPay trả `{"RspCode":"00","Message":"Confirm Success"}`.
- Return URL do HiTechPay tự sinh, không cần cấu hình.
- Đăng ký **IP máy chủ** với VNPAY nếu được yêu cầu (gọi `querydr` / `refund`).
- Hoàn tiền: toàn phần (loại 02) hoặc một phần (03) qua dashboard/API.

### ZaloPay
- Sandbox: `https://sb-openapi.zalopay.vn/`, thanh toán thử bằng ứng dụng ZaloPay sandbox / thẻ test do ZaloPay cấp.
- Callback: HiTechPay tự gửi `callback_url` trong mỗi đơn — không cần khai báo thêm.
- Hoàn tiền + truy vấn hoàn tiền tự động qua API.
- Lưu ý `app_id` phải là số.

### Viettel Money (beta)
- Đối chiếu tài liệu tích hợp Viettel cấp (tên trường, URL sandbox/production, định dạng phản hồi IPN) với
  [connectors-vn.md](../connectors-vn.md#viettel-money-viettelmoney--beta) trước khi chạy thật.
- Khai báo **URL kết quả/IPN** = Webhook URL tại Viettel. Return URL do HiTechPay sinh.
- Không có API truy vấn: trạng thái lấy từ IPN và tham số return đã ký. Hoàn tiền trên portal Viettel.

### payOS
- Lấy Client ID / API Key / Checksum Key tại `my.payos.vn` → *Kênh thanh toán*.
- **Webhook**: trong kênh thanh toán → *Webhook URL* → dán Webhook URL → payOS gửi request xác nhận
  (HiTechPay trả `{"success":true}`).
- Không có API hoàn tiền (chuyển khoản lại thủ công). Huỷ link thanh toán: *Cancel* payment trên dashboard/API.

### SePay
- Liên kết tài khoản ngân hàng nhận tiền trong `my.sepay.vn`; tạo **API Token** (*Cấu hình công ty → API Access*).
- **Webhook**: *Webhooks → Thêm* → URL = Webhook URL, kiểu xác thực **API Key**, đặt một chuỗi bí mật →
  nhập đúng chuỗi đó vào ô *Merchant secret / Webhook secret* của connector trên dashboard.
- Khách quét VietQR, nội dung chuyển khoản chứa mã `HTP…` (12 ký tự sau HTP) — **khách không được sửa nội dung**.
  Chuyển thiếu → trạng thái `partially_captured`; chuyển nhiều lần cùng mã được cộng dồn.
- Hoàn tiền: thủ công.

### Pay2S (beta)
- Như SePay: liên kết ngân hàng, tạo webhook với **Bearer token** → nhập token vào *Webhook secret* của connector.
- Kiểm tra định dạng webhook thực tế trên dashboard Pay2S (HiTechPay chấp nhận dạng lô `transactions[]` và một giao dịch).

## 4.4 Kiểm thử một giao dịch

```bash
curl -X POST https://api.<domain>/payments \
  -H "Content-Type: application/json" -H "api-key: <SECRET_API_KEY_CỦA_MERCHANT>" \
  -d '{
    "amount": 10000, "currency": "VND", "confirm": true,
    "profile_id": "<profile_id>",
    "payment_method": "real_time_payment", "payment_method_type": "viet_qr",
    "payment_method_data": { "real_time_payment": { "viet_qr": {} } },
    "routing": { "type": "single", "data": { "connector": "sepay", "merchant_connector_id": "<mca_id>" } },
    "return_url": "https://shop.example.vn/thanh-toan/ket-qua",
    "description": "Don hang 1001"
  }'
```

Kết quả mong đợi: `status = requires_customer_action` và `next_action` chứa `redirect_to_url` (MoMo, VNPAY,
ZaloPay, payOS, Viettel) hoặc `qr_code_information` (SePay, Pay2S, QR của MoMo/ZaloPay/payOS). Sau khi thanh toán:

```bash
curl -s https://api.<domain>/payments/<payment_id>?force_sync=true -H "api-key: <SECRET_API_KEY>" | jq .status
# → "succeeded"
```

Nếu dùng định tuyến tự động, bỏ trường `routing` và cấu hình *Routing* trên dashboard (ưu tiên, volume split, fallback).

## 4.5 Định tuyến gợi ý cho thị trường VN

| Nhu cầu | Cấu hình |
|---|---|
| Chi phí thấp nhất cho chuyển khoản | VietQR → SePay/payOS (phí cố định thấp) |
| Ví điện tử | wallet/momo → MoMo; ZaloPay qua `local_bank_redirect`/`viet_qr` của connector ZaloPay |
| Thẻ ATM nội địa | `local_bank_redirect` → VNPAY (khách chọn ngân hàng trên trang VNPAY) |
| Dự phòng | Rule fallback: VNPAY lỗi → ZaloPay |
