# 016 — Payout VN (chi hộ / chuyển tiền ra)

Trạng thái: Chưa làm
Ưu tiên: P2 (phụ thuộc kết luận pháp lý task 007)

## Bối cảnh
Hyperswitch hỗ trợ luồng payout (`payouts` feature). Ứng viên: payOS payout (chuyển khoản Napas 247), SePay, ngân hàng trực tiếp.

## Các bước
1. Xác định nhà cung cấp & hợp đồng; tài liệu API payout.
2. Cài flow `PoCreate/PoFulfill/PoSync` cho connector (xem connector payout upstream như `wise`, `adyenplatform`).
3. Phương thức payout: bank transfer VN (số tài khoản + BIN, dùng `vn_utils::BANK_BINS`).
4. Dashboard: bật Payout processor; SDK Node/PHP thêm resource `payouts`.

## Tiêu chí hoàn thành
- Payout sandbox thành công, webhook cập nhật trạng thái.
