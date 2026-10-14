# 017 — Plugin Haravan / Sapo

Trạng thái: Chưa làm
Ưu tiên: P3

## Các bước
1. Đăng ký đối tác/app trên Haravan và Sapo; đọc tài liệu cổng thanh toán tuỳ chỉnh (payment app / redirect gateway).
2. Viết dịch vụ cầu nối (Node, dùng `sdks/node`) đặt trong `plugins/haravan`, `plugins/sapo`: nhận yêu cầu thanh toán từ nền tảng,
   tạo payment HiTechPay, redirect khách, nhận webhook HiTechPay → báo kết quả cho nền tảng (ký theo chuẩn của nền tảng).
3. Test với cửa hàng thử; tài liệu cài đặt.

## Tiêu chí hoàn thành
- Đơn hàng thật trên cửa hàng thử Haravan/Sapo thanh toán qua HiTechPay.
