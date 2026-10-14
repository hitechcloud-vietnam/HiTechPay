# 013 — Helm: cài trên cluster thật

Trạng thái: Chưa làm
Ưu tiên: P1

## Bối cảnh
`deploy/helm/values-hitechpay.yaml` đã `helm lint`/`helm template` với chart nguồn (stack 0.2.28, hyperswitch-app 1.3.4) nhưng
**chưa cài lên cluster**; chart published 0.2.28 ghim hyperswitch-app 1.3.1 (chưa kiểm). Sub-chart bên thứ ba bị stub khi render offline.

## Các bước
1. Cluster thử (k3d/kind hoặc cloud) + ingress-nginx + cert-manager.
2. Làm theo [docs/trien-khai/03-kubernetes-helm.md](../docs/trien-khai/03-kubernetes-helm.md) với chart published; ghi các khác biệt
   so với chart nguồn, sửa values.
3. Kiểm tra: migration job, router health, dashboard đăng nhập, SDK `/web/0.134.0/v1/HyperLoader.js`, connector VN có base URL.
4. Thêm job CI `helm lint` + `helm template` (cài helm từ GitHub release).

## Tiêu chí hoàn thành
- Giao dịch sandbox chạy trên cluster; job CI Helm xanh.
