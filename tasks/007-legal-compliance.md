# 007 — Pháp lý & tuân thủ trước go-live

Trạng thái: Chưa làm (cần pháp chế/luật sư)
Ưu tiên: P0

## Việc cần quyết định
1. Mô hình: HiTechPay là **nền tảng kỹ thuật** (merchant tự ký với từng cổng) hay **trung gian thanh toán** (thu hộ/chi hộ).
   Mô hình sau cần giấy phép NHNN (Nghị định 52/2024/NĐ-CP về thanh toán không dùng tiền mặt).
2. Bảo vệ dữ liệu cá nhân (Nghị định 13/2023/NĐ-CP): hồ sơ đánh giá tác động, vị trí lưu trữ dữ liệu, chính sách xử lý.
3. PCI DSS: nếu lưu/xử lý dữ liệu thẻ (card vault) → phạm vi đánh giá; nếu chỉ redirect/QR → SAQ A.
4. Rà soát nội dung giao diện nói "HiTechPay … PCI DSS compliant", template e-mail Apple Pay
   (whitelabel đã thay tên Hyperswitch → HiTechPay; cần xác nhận hoặc sửa câu chữ).
5. Ghi nhận bản quyền: Apache-2.0 của Juspay (file `NOTICE`), không dùng thương hiệu "Hyperswitch" cho sản phẩm thương mại.

## Tiêu chí hoàn thành
- Văn bản ý kiến pháp lý; checklist [docs/trien-khai/07-checklist-go-live.md](../docs/trien-khai/07-checklist-go-live.md) mục *Pháp lý* đã tick.
