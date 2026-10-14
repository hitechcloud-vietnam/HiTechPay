# 006 — Pay2S: đối chiếu định dạng webhook & API

Trạng thái: Chưa làm
Ưu tiên: P1

## Bối cảnh
`pay2s` tạo VietQR cục bộ, nhận webhook `Authorization: Bearer <token>`, chấp nhận cả dạng lô `{"transactions":[…]}`
lẫn một giao dịch (camelCase/snake_case). PSync cục bộ (không gọi API). Base URL `https://my.pay2s.vn/` là giả định.

## Các bước
1. Lấy tài liệu webhook/API từ dashboard Pay2S; gửi webhook test vào môi trường dev, lưu payload mẫu.
2. Điều chỉnh `Pay2sTransaction` (alias trường), cách xác thực header.
3. Nếu Pay2S có API tra cứu giao dịch: cài PSync thật như SePay (`userapi/transactions/list`), bỏ `should_build_psync_response_locally`.
4. Thêm payload thật (ẩn số tài khoản) vào unit test.

## Tiêu chí hoàn thành
- Webhook thật của Pay2S cập nhật đúng trạng thái; bỏ nhãn beta.
