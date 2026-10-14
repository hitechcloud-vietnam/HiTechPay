# 6. Vận hành

## 6.1 Giám sát

| Kiểm tra | Cách làm | Ngưỡng cảnh báo gợi ý |
|---|---|---|
| Router sống | `GET https://api.<domain>/health` | ≠ 200 trong 1 phút |
| Router đầy đủ phụ thuộc | `GET /health/ready` (DB, Redis, …) | lỗi bất kỳ |
| Tỷ lệ thành công | Dashboard → Analytics (bật ClickHouse/OLAP) hoặc truy vấn DB | giảm > 10 điểm % so với trung bình 1 giờ |
| Webhook từ cổng | log router `incoming_webhook` theo connector | không có webhook trong X phút giờ cao điểm |
| PostgreSQL | kết nối, dung lượng, replication lag | > 80% connections / disk |
| Redis | bộ nhớ, eviction | eviction > 0 |
| Chứng chỉ TLS | ngày hết hạn | < 14 ngày |

Kubernetes: bật `--set hyperswitch-monitoring.enabled=true` (Prometheus/VictoriaMetrics, Grafana, Loki).
Compose: dùng profile `monitoring` của upstream (`upstream/hyperswitch/docker-compose.yml`) hoặc node-exporter +
cAdvisor + Uptime Kuma cho mức cơ bản.

## 6.2 Log

```bash
$(cat deploy/.compose-cmd) logs -f --since 30m router
$(cat deploy/.compose-cmd) logs router | grep -i '"connector":"vnpay"' | tail
kubectl -n hitechpay logs deploy/hyperswitch-router -f            # tên deployment xem bằng kubectl get deploy
```

Log router là JSON, có `payment_id`, `attempt_id`, `connector`, `flow`. Khoá/thẻ được che (masking) tự động.

## 6.3 Sao lưu và khôi phục

| Việc | Lệnh |
|---|---|
| Sao lưu ngay | `BACKUP_DIR=/var/backups/hitechpay scripts/backup-db.sh` |
| Sao lưu + đẩy S3 | `S3_URI=s3://bucket/hitechpay scripts/backup-db.sh` |
| Lịch hằng ngày | `/etc/cron.d/hitechpay-backup` (do `install-ubuntu.sh` tạo, 02:15, giữ 14 ngày) |
| Khôi phục | `scripts/restore-db.sh /var/backups/hitechpay/db-YYYYMMDD-HHMMSS.dump` |
| Kubernetes / DB managed | Dùng snapshot + PITR của dịch vụ DB; hoặc `kubectl exec` vào pod postgres chạy `pg_dump -Fc` |

Kèm theo mọi bản sao lưu: `deploy/.env` (hoặc Secret K8s) — **master key bắt buộc** để đọc dữ liệu đã mã hoá.
Diễn tập khôi phục ít nhất mỗi quý trên máy thử (khôi phục → `scripts/smoke-test.sh` → kiểm tra vài payment cũ).

## 6.4 Xoay khoá

| Khoá | Quy trình |
|---|---|
| Khoá cổng thanh toán (MoMo, VNPAY…) | Tạo khoá mới tại cổng → cập nhật connector trên dashboard → kiểm tra giao dịch thử → thu hồi khoá cũ |
| API key merchant | Tạo key mới → merchant cập nhật → xoá key cũ (Developers → API Keys) |
| Admin API key, JWT secret | Đổi trong `.env`/Secret → khởi động lại router (JWT: mọi phiên đăng nhập hết hiệu lực) |
| Master encryption key | **Không xoay** bằng cách thay giá trị. Cần quy trình migrate dữ liệu riêng (key manager / encryption service) |
| Webhook secret SePay/Pay2S | Đổi trên cổng và trên connector cùng lúc (trong khoảng nghỉ ít giao dịch) |

## 6.5 Mở rộng

- Router là stateless → tăng replica (K8s HPA) hoặc thêm máy sau load balancer (Compose: nhiều máy dùng chung DB/Redis).
- PostgreSQL: bật replica đọc (`replica_database` trong cấu hình router).
- Redis: chuyển sang cluster (`redis.cluster_enabled`) khi lưu lượng lớn.
- Bật scheduler để retry/đồng bộ trạng thái tự động (profile `scheduler`).

## 6.6 Nâng cấp

| Loại | Lệnh |
|---|---|
| Bản HiTechPay mới | `git pull origin main && scripts/apply-patches.sh && scripts/prepare-docker-context.sh` rồi build/deploy |
| Bản upstream mới | `scripts/upgrade-upstream.sh hyperswitch v1.xxx.0` → sửa xung đột → `scripts/export-patches.sh` → chạy test (CI) → merge `main` |
| Thêm cổng thanh toán | viết connector trong submodule (nhánh `hitechpay`) → thêm vào `tools/register_connectors.py` + `tools/register_control_center.py` → chạy 2 script → build lại euclid wasm → `scripts/export-patches.sh` |

Luôn: sao lưu DB → triển khai sandbox → giao dịch thử từng cổng → production.

## 6.7 Xử lý sự cố

| Triệu chứng | Nguyên nhân thường gặp | Cách xử lý |
|---|---|---|
| Router không khởi động, lỗi `base_url` rỗng | Thiếu cấu hình connector (Helm chart gốc) | Dùng `values-hitechpay.yaml` / config đã vá |
| `migration-runner` thất bại | Sai mật khẩu DB, chặn mạng tải diesel/just | Kiểm tra `HTP_DB_*`, mở egress github.com |
| Build image lỗi git / submodule | Chưa chạy `scripts/prepare-docker-context.sh` | Chạy script rồi build lại |
| Thanh toán treo `requires_customer_action` | Webhook không tới HiTechPay | Kiểm tra Webhook URL đã khai báo ở cổng, firewall, `HTP_API_PUBLIC_URL` đúng HTTPS |
| Webhook bị từ chối (401/verification failed) | Sai Secret/Checksum key, sai webhook secret SePay/Pay2S | Nhập lại khoá trên connector |
| VNPAY báo "Sai chữ ký" (97) | Sai `vnp_HashSecret` hoặc TmnCode | Kiểm tra khoá; không sửa tham số URL |
| VNPAY "Không tìm thấy giao dịch" (91) khi đồng bộ | Khách chưa thanh toán | Bình thường, trạng thái vẫn chờ |
| SePay nhận tiền nhưng đơn không cập nhật | Khách sửa nội dung CK, mất mã `HTP…` | Đối soát thủ công, cập nhật đơn; nhắc khách giữ nguyên nội dung |
| ZaloPay `-401`/`-402` | Sai MAC, sai app_id | Kiểm tra key1, app_id là số |
| Dashboard không thấy cổng VN | Image dashboard cũ (euclid wasm cũ) | Build lại image dashboard từ patch mới nhất |
| Checkout không hiện VietQR | Chưa bật `viet_qr` cho profile, hoặc tiền tệ ≠ VND | Bật payment method, `currency: VND` |
