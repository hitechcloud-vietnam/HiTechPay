# 018 — Hoá đơn điện tử sau thanh toán

Trạng thái: Chưa làm
Ưu tiên: P3

## Bối cảnh
Merchant VN cần xuất hoá đơn điện tử (Nghị định 123/2020, Thông tư 78/2021) sau thanh toán.

## Các bước
1. Chọn nhà cung cấp (VNPT Invoice, Viettel S-Invoice, MISA meInvoice, BKAV…); đọc API.
2. Dịch vụ `services/einvoice` (ngoài router): lắng nghe webhook `payment_succeeded` của HiTechPay, tạo hoá đơn từ
   metadata đơn hàng (MST người mua, tên, địa chỉ), lưu số hoá đơn vào `metadata` payment.
3. Cấu hình theo merchant trên dashboard (hoặc trang cấu hình riêng).

## Tiêu chí hoàn thành
- Thanh toán thành công tự động sinh hoá đơn nháp/phát hành ở môi trường thử của nhà cung cấp.
