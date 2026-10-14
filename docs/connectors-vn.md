# Cổng thanh toán Việt Nam / Vietnamese connectors

Tất cả connector nằm trong `upstream/hyperswitch/crates/hyperswitch_connectors/src/connectors/<name>`
(patch `patches/hyperswitch/0004-*`). Trợ giúp chung ở `src/vn_utils.rs`.

## Khái niệm chung

- **Tiền tệ:** chỉ `VND` (đơn vị nhỏ nhất = 1 đồng, Hyperswitch coi VND là zero-decimal).
- **Mã đơn hàng gửi cổng:** `connector_request_reference_id` (= `attempt_id`). Webhook được tra
  cứu theo attempt id hoặc mã giao dịch của cổng.
- **Webhook URL** đăng ký tại cổng: `https://<api>/webhooks/<merchant_id>/<merchant_connector_id>`
  (hiển thị trong dashboard sau khi tạo connector).
- **Return URL:** Hyperswitch tự sinh và gửi cho cổng; sau khi khách quay lại, Hyperswitch đồng bộ
  trạng thái (PSync).
- **Mã chuyển khoản** (SePay, Pay2S, payOS): `HTP` + 12 ký tự hex, sinh từ attempt id
  (`vn_utils::transfer_code`). Ngân hàng có thể đổi chữ hoa/thường hoặc thêm chữ — bộ tách mã vẫn nhận ra.
- **Cổng redirect không có API tạo giao dịch** (VNPAY, Viettel Money): URL thanh toán được ký cục bộ.
  Trang thanh toán chọn theo thứ tự: metadata `payment_url` → metadata `environment`
  (`production`/`live` hoặc `sandbox`) → cờ `test_mode` của connector (`false` → production).

## Ánh xạ khoá trong dashboard

| Connector | Auth type | `api_key` | `key1` | `api_secret` | Webhook secret |
|---|---|---|---|---|---|
| momo | SignatureKey | Partner Code | Access Key | Secret Key | (không cần, dùng Secret Key) |
| vnpay | BodyKey | vnp_TmnCode | vnp_HashSecret | — | (không cần) |
| zalopay | SignatureKey | app_id | key1 | key2 | (không cần) |
| viettelmoney | SignatureKey | merchant_code | access_code | hash_key | (không cần) |
| payos | SignatureKey | Client ID | API Key | Checksum Key | (không cần) |
| sepay | SignatureKey | API Token | Số tài khoản | Ngân hàng (MB, VCB, … hoặc BIN) | **Webhook API key** |
| pay2s | SignatureKey | API Token | Số tài khoản | Ngân hàng (ACB, … hoặc BIN) | **Webhook token** |

## MoMo (`momo`)
- Tạo: `POST /v2/gateway/api/create`, `requestType` = `captureWallet` (ví) hoặc `payWithATM` (thẻ ATM).
  Chữ ký HMAC-SHA256 trên `accessKey&amount&extraData&ipnUrl&orderId&orderInfo&partnerCode&redirectUrl&requestId&requestType`.
- Trả về `payUrl` (redirect) và `qrCodeUrl` (hiển thị QR trong SDK).
- PSync: `POST /v2/gateway/api/query` (orderId = attempt id). `resultCode` 0 → Charged, 9000 → Authorized,
  1000/8000 → chờ khách, 7000/7002 → Pending, 1006 → khách huỷ.
- Hoàn tiền: `POST /v2/gateway/api/refund` (cần `transId` — có sau khi giao dịch thành công). RSync đọc `refundTrans` của query.
- IPN: xác thực chữ ký bằng Secret Key của tài khoản connector.
- Sandbox: `https://test-payment.momo.vn/`, production: `https://payment.momo.vn/` (cấu hình `momo.base_url`).

## VNPAY (`vnpay`)
- Thanh toán: tham số `vnp_*` (v2.1.0), sắp xếp theo khoá, URL-encode kiểu PHP, ký HMAC-SHA512 —
  tạo cục bộ (hook `should_build_authorize_response_locally`). VietQR → `vnp_BankCode=VNPAYQR`;
  ngân hàng nội địa → không gửi bank code (khách chọn trên trang VNPAY). Hết hạn sau 15 phút.
- `vnp_CreateDate` lưu trong `connector_metadata` để gọi `querydr` / `refund`.
- PSync / hoàn tiền: `POST merchant_webapi/api/transaction` (`querydr`, `refund` loại 02 toàn phần / 03 một phần).
- IPN: VNPAY gọi **GET** với `vnp_SecureHash`; HiTechPay trả `{"RspCode":"00","Message":"Confirm Success"}`
  (01 nếu không tìm thấy đơn). Đăng ký IPN URL ở cổng quản trị VNPAY.

## ZaloPay (`zalopay`)
- `POST /v2/create` (form): `app_trans_id = yymmdd_<attempt id>` (giờ VN), `embed_data` chứa `redirecturl`,
  `preferred_payment_method` (`["vietqr"]` cho VietQR) và `hitechpay_ref` (dùng tra cứu callback).
  MAC = HMAC-SHA256(key1, `app_id|app_trans_id|app_user|amount|app_time|embed_data|item`).
- PSync `POST /v2/query`; hoàn tiền `POST /v2/refund` (`m_refund_id = yymmdd_appid_<refund id>`), RSync `POST /v2/query_refund`.
- Callback: `{data, mac, type}`, MAC = HMAC-SHA256(key2, data); trả `{"return_code":1}`.
- Lưu ý: `app_trans_id`/`m_refund_id` được dựng lại theo ngày giờ VN khi xử lý phản hồi — giao dịch tạo đúng lúc
  nửa đêm có thể lệch ngày (rủi ro rất nhỏ, đã ghi nhận trong roadmap).

## Viettel Money (`viettelmoney`) — beta
- URL cổng `PaymentGateway/payment` (version 2.0, command PAYMENT), `check_sum` = base64 HMAC-SHA1 trên
  `access_code+billcode+command+merchant_code+order_id+trans_amount+version`.
- Kết quả (IPN và tham số return): checksum trên
  `access_code+billcode+cust_msisdn+error_code+merchant_code+order_id+payment_status+trans_amount+vt_transaction_id`.
  `error_code=00` & `payment_status=1` → Charged.
- Không có API truy vấn → PSync xử lý cục bộ (hook `should_build_psync_response_locally`).
- Hoàn tiền: thực hiện trên portal Viettel.
- ⚠️ Đặc tả Viettel Money được cung cấp riêng cho đối tác; đối chiếu tài liệu tích hợp nhận từ Viettel
  (tên trường, URL sandbox/production, định dạng phản hồi IPN) trước khi chạy thật.

## payOS (`payos`)
- `POST /v2/payment-requests` (header `x-client-id`, `x-api-key`), `orderCode` = số nguyên sinh từ attempt id,
  `description` = mã `HTP…` (≤ 25 ký tự), chữ ký trên `amount&cancelUrl&description&orderCode&returnUrl`.
- Trả `checkoutUrl` (redirect) và `qrCode` (VietQR hiển thị trong SDK).
- PSync `GET /v2/payment-requests/{orderCode}`; huỷ `POST /v2/payment-requests/{orderCode}/cancel`.
- Webhook: chữ ký HMAC-SHA256(checksumKey, các khoá `data` sắp xếp). Hoàn tiền: không có API.

## SePay (`sepay`)
- VietQR (NAPAS EMVCo, CRC16) tạo cục bộ cho tài khoản ngân hàng của merchant, nội dung CK = mã `HTP…`;
  kèm ảnh `https://qr.sepay.vn/img?...`. QR hiển thị 30 phút.
- Webhook SePay: kiểm tra header `Authorization: Apikey <webhook key>` (nhập vào *Webhook secret* của connector),
  tách mã `HTP…` từ `code`/`content`, cộng dồn số tiền → Charged / PartialCharged.
- PSync: `GET /userapi/transactions/list?account_number=…` (Bearer API token).
- Hoàn tiền: chuyển khoản thủ công.

## Pay2S (`pay2s`) — beta
- Giống SePay (VietQR cục bộ + mã `HTP…`), ảnh QR từ `img.vietqr.io`.
- Webhook: header `Authorization: Bearer <token>`; chấp nhận cả dạng lô `{"transactions":[…]}` và một giao dịch,
  tên trường camelCase / snake_case.
- PSync xử lý cục bộ (trạng thái đến từ webhook).
- ⚠️ Đối chiếu định dạng webhook trong dashboard Pay2S trước khi chạy thật.

## Kiểm thử
- Unit test: `cargo test -p hyperswitch_connectors --features "v1 payouts frm" --lib -- vn_utils momo vnpay zalopay payos sepay pay2s viettelmoney`
- Test sandbox (cần credential trong `crates/router/tests/connectors/sample_auth.toml`):
  `cargo test -p router --test connectors -- <name> --ignored`
