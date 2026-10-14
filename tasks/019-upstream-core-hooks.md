# 019 — Đóng góp 2 hook lõi lên upstream Juspay

Trạng thái: Chưa làm
Ưu tiên: P3

## Bối cảnh
Patch `0001`, `0003` của `patches/hyperswitch/` thêm `ConnectorSpecifications::should_build_authorize_response_locally()` và
`should_build_psync_response_locally()` (mặc định `false`, không ảnh hưởng connector khác). Đưa lên upstream giúp giảm patch phải duy trì.

## Các bước
1. Fork `juspay/hyperswitch`, áp 2 patch lên `main` upstream, viết mô tả + test (connector dummy dùng hook).
2. Mở PR theo CONTRIBUTING của upstream; theo dõi review.
3. Khi được merge: nâng upstream (`scripts/upgrade-upstream.sh`) và bỏ 2 patch khỏi series.

## Tiêu chí hoàn thành
- PR upstream được merge, patch series HiTechPay bớt 2 patch.
