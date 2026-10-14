# 012 — Playwright visual snapshots theo thương hiệu mới

Trạng thái: Chưa làm
Ưu tiên: P2

## Bối cảnh
147 ảnh baseline trong `upstream/hyperswitch-control-center/playwright-tests/visual-testing` vẫn là giao diện Hyperswitch.
Assertion chữ đã được cập nhật (patch `0004-test-e2e-…`).

## Các bước
1. Chạy stack sandbox + dashboard; trong submodule: `npm ci && npx playwright install --with-deps`.
2. `npx playwright test --update-snapshots` cho thư mục visual-testing; xem lại ảnh bằng mắt.
3. Commit trong submodule → `scripts/export-patches.sh` (patch nhị phân lớn — cân nhắc Git LFS).

## Tiêu chí hoàn thành
- Bộ e2e Playwright chạy xanh trên CI (thêm job dùng docker compose).
