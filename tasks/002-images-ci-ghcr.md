# 002 — Images CI xanh & GHCR public

Trạng thái: Đang chờ kết quả CI (đã sửa lỗi build router)
Ưu tiên: P0

## Bối cảnh
`.github/workflows/images.yml` build 4 image: router, scheduler, dashboard, checkout-sdk.
- Lần chạy #1: dashboard ✅, checkout-sdk ✅, router ❌ — `redis_interface`: *Either feature "fred" or "redis-rs" must be enabled*,
  vì Dockerfile upstream build `--no-default-features --features release,v1`.
- Đã sửa (commit `fix(images): build router and scheduler with the redis-rs backend`): truyền
  `EXTRA_FEATURES=--features redis-rs` (giống CI upstream `release,v2,redis-rs`), cả trong `deploy/docker-compose.yml`.

## Các bước còn lại
1. Theo dõi run *Images* mới nhất trên `main` (Actions). Build router release trên runner GitHub mất ~60–120 phút
   (`timeout-minutes: 300`). Nếu hết dung lượng đĩa: mở rộng bước *Free disk space* hoặc dùng runner lớn hơn.
2. Nếu lỗi biên dịch khác ở bộ feature `release`: tái hiện bằng
   `cd upstream/hyperswitch && cargo check -p router --no-default-features --features release,v1,redis-rs`.
3. Sau khi xanh: GitHub → *Packages* → 4 package `hitechpay-*` → *Package settings* → **Change visibility → Public**
   (Helm chart không có imagePullSecrets), hoặc cấu hình pull secret theo [docs/trien-khai/03-kubernetes-helm.md](../docs/trien-khai/03-kubernetes-helm.md).
4. Thêm job smoke test: `docker run … router --version` / healthcheck với Postgres+Redis service container.

## Tiêu chí hoàn thành
- Workflow *Images* xanh trên `main`, 4 image có tag `v1.127.0`/`v1.38.9`/`v0.134.0`, `sha-*`, `main`.
- `docker pull ghcr.io/hitechcloud-vietnam/hitechpay-router:main` thành công không cần đăng nhập.
