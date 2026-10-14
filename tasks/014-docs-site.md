# 014 — Trang tài liệu docs.hitechpay.vn

Trạng thái: Chưa làm
Ưu tiên: P2

## Bối cảnh
White-label đổi link tài liệu trong dashboard sang `https://docs.hitechpay.vn/<đường dẫn upstream>`; trang này chưa tồn tại.
Nguồn có sẵn: `upstream/hyperswitch-docs` (submodule, đã deinit để tiết kiệm đĩa — `git submodule update --init upstream/hyperswitch-docs`)
và `docs/` của repo này.

## Các bước
1. Chọn công cụ (Mintlify/Docusaurus giống upstream), dựng site từ hyperswitch-docs + docs/ (đặc biệt `docs/trien-khai/`).
2. Giữ cấu trúc URL upstream cho các trang dashboard đang trỏ tới (hoặc thêm redirect).
3. Viết hướng dẫn API tiếng Việt cho các cổng VN; triển khai qua GitHub Pages/Vercel.

## Tiêu chí hoàn thành
- `https://docs.hitechpay.vn` hoạt động; link trong dashboard không 404.
