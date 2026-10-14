# 003 — Connector đợt 2: OnePay, AppotaPay, 9Pay + sửa ZaloPay lúc nửa đêm

Trạng thái: Đang làm (agent tự động) — bản nháp trên nhánh `wip/connectors-batch2`
Ưu tiên: P0

## Bối cảnh
- Code mới nằm trong submodule `upstream/hyperswitch` (nhánh `hitechpay`, **chưa commit** tại thời điểm viết):
  `crates/hyperswitch_connectors/src/connectors/{onepay,appotapay,ninepay}.rs` + `transformers.rs`,
  test `crates/router/tests/connectors/{onepay,appotapay,ninepay}.rs`.
- Đăng ký: `tools/register_connectors.py`, `tools/register_control_center.py` (đã có mục mới trên nhánh WIP).
- ZaloPay: `app_trans_id`/`m_refund_id` đang dựng lại theo ngày hiện tại khi xử lý phản hồi → lệch ngày nếu giao dịch
  tạo đúng lúc nửa đêm (giờ VN).

## Các bước
1. `cd upstream/hyperswitch && git status` — lấy code từ nhánh WIP nếu làm trên máy khác.
2. `cargo check -p router` và `cargo check -p router --tests` — sạch lỗi & cảnh báo.
3. Unit test:
   `CARGO_PROFILE_DEV_DEBUG=0 cargo test -p hyperswitch_connectors --features "v1 payouts frm" --lib -- vn_utils momo vnpay zalopay payos sepay pay2s viettelmoney onepay appotapay ninepay`
4. `cargo +nightly fmt --all`.
5. Dashboard: `python3 tools/register_control_center.py upstream/hyperswitch-control-center`, rồi build lại euclid WASM:
   ```bash
   rustup target add wasm32-unknown-unknown   # cài wasm-pack 0.13.x nếu chưa có
   cd upstream/hyperswitch
   CARGO_TARGET_DIR=/tmp/wasm-target wasm-pack build --target web --out-dir /tmp/euclid-wasm-out \
     --out-name euclid crates/euclid_wasm -- --features dummy_connector,v1
   cp /tmp/euclid-wasm-out/euclid{.d.ts,.js,_bg.wasm,_bg.wasm.d.ts} ../hyperswitch-control-center/public/hyperswitch/wasm/
   ```
   (bước `wasm-opt` có thể lỗi nếu không tải được binaryen — file đầu ra vẫn dùng được).
6. Commit trong 2 submodule (nhánh `hitechpay`) → `scripts/export-patches.sh` → nhánh `feat/connectors-batch2` → merge `main`.
7. Thêm 3 connector vào bước unit test của `.github/workflows/ci.yml`.
8. Xoá nhánh `wip/connectors-batch2`.

## Tiêu chí hoàn thành
- CI xanh; `docs/connectors-vn.md` có mục OnePay/AppotaPay/9Pay (đánh dấu ⚠️ các điểm cần đối chiếu tài liệu chính thức).
- Unit test mới cho ZaloPay chứng minh không lệch ngày qua nửa đêm.
