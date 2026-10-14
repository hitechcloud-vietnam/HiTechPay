# 001 — Đặt `main` làm nhánh mặc định

Trạng thái: Chưa làm (cần quyền admin repo — công cụ tự động không có quyền đổi cài đặt này)
Ưu tiên: P0

## Bối cảnh
Repo `hitechcloud-vietnam/HiTechPay` được push lần đầu từ nhánh `hitechcloud/happy-wozniak-kxpnrx`, nên GitHub
đặt nhánh đó làm mặc định. `main` đã chứa toàn bộ code và là nhánh chính.

## Các bước
1. GitHub → repo → **Settings → General → Default branch** → biểu tượng ⇄ → chọn `main` → *Update*.
2. **Settings → Branches → Add branch protection rule** cho `main`:
   - Require a pull request before merging (1 approval).
   - Require status checks: `Patches apply on pinned upstream`, `VN connector unit tests`, `Node SDK`, `PHP SDK`.
   - Không cho force-push.
3. Xoá các nhánh đã merge: `hitechcloud/happy-wozniak-kxpnrx`, `feat/*`, `docs/deployment-guide`, `fix/images-redis-feature`
   (sau khi xác nhận đã nằm trong `main`: `git branch -r --merged origin/main`).

## Tiêu chí hoàn thành
- `git ls-remote --symref origin HEAD` trả về `refs/heads/main`.
- Image `latest` trên GHCR được gắn (workflow chỉ gắn `latest` cho nhánh mặc định).
