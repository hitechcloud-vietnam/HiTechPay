# 010 — WHMCS: kiểm thử thật & trạng thái hoàn tiền pending

Trạng thái: Chưa làm
Ưu tiên: P2

## Bối cảnh
Module `plugins/whmcs/` có test giả lập (25/25). Hoàn tiền mà HiTechPay trả `pending` đang được báo cho WHMCS là `success`
(WHMCS chỉ có success/declined/error).

## Các bước
1. Cài trên WHMCS bản dùng thử (cần license dev), kiểm thử hoá đơn với VietQR/MoMo/thẻ, webhook, hoàn tiền.
2. Hoàn tiền pending: ghi chú vào hoá đơn + cron (`hooks`) đồng bộ trạng thái refund, ghi `logTransaction` khi thất bại.
3. Thêm `php plugins/whmcs/tests/run.php` vào CI (đã có job PHP SDK — thêm bước).

## Tiêu chí hoàn thành
- Hoá đơn thật thanh toán + hoàn tiền thành công; CI chạy test WHMCS.
