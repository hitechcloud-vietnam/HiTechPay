# 3. Triển khai Kubernetes (Helm)

Chi tiết từng giá trị Helm và lệnh cài đặt gốc nằm ở [deploy/helm/README.md](../../deploy/helm/README.md).
Tài liệu này là quy trình đầy đủ từ đầu đến cuối.

## 3.1 Kiến trúc trên cluster

| Workload | Image | Replica khuyến nghị (prod) |
|---|---|---|
| router (API) | `ghcr.io/hitechcloud-vietnam/hitechpay-router:v1.127.0` | 3+ (HPA theo CPU 70%) |
| consumer / producer (scheduler) | `…/hitechpay-scheduler:v1.127.0` | 2 / 1 |
| drainer | `docker.juspay.io/juspaydotin/hyperswitch-drainer:v1.127.0` | 1 (khi bật KV) |
| dashboard | `…/hitechpay-dashboard:v1.38.9` | 2 |
| checkout SDK | `…/hitechpay-checkout-sdk:v0.134.0` | 2 |
| PostgreSQL, Redis | managed (khuyến nghị) hoặc subchart bitnami | HA |

Tag image = phiên bản upstream đã vá (chart dùng chính các số phiên bản này để chạy migration và đặt đường dẫn SDK).

## 3.2 Chuẩn bị

1. Cluster K8s ≥ 1.24, `kubectl` + `helm` ≥ 3.12 trỏ đúng context.
2. Ingress controller (ingress-nginx) và cert-manager:

```bash
helm repo add ingress-nginx https://kubernetes.github.io/ingress-nginx
helm upgrade --install ingress-nginx ingress-nginx/ingress-nginx -n ingress-nginx --create-namespace
helm repo add jetstack https://charts.jetstack.io
helm upgrade --install cert-manager jetstack/cert-manager -n cert-manager --create-namespace --set crds.enabled=true
kubectl apply -f - <<'EOF'
apiVersion: cert-manager.io/v1
kind: ClusterIssuer
metadata: { name: letsencrypt-prod }
spec:
  acme:
    server: https://acme-v02.api.letsencrypt.org/directory
    email: admin@hitechpay.vn
    privateKeySecretRef: { name: letsencrypt-prod }
    solvers: [ { http01: { ingress: { ingressClassName: nginx } } } ]
EOF
```

3. DNS `api.`, `dashboard.`, `checkout.<domain>` → IP LoadBalancer của ingress-nginx
   (`kubectl -n ingress-nginx get svc ingress-nginx-controller`).
4. Image: push lên `main` để workflow `images.yml` build; vào GitHub → *Packages* → đặt 4 package
   `hitechpay-*` thành **public** (chart không có imagePullSecrets). Nếu muốn private, thêm `imagePullSecrets`
   vào service account của namespace:

```bash
kubectl -n hitechpay create secret docker-registry ghcr --docker-server=ghcr.io \
  --docker-username=<user> --docker-password=<PAT có read:packages>
kubectl -n hitechpay patch serviceaccount default -p '{"imagePullSecrets":[{"name":"ghcr"}]}'
```

## 3.3 Cài đặt

```bash
git clone https://github.com/hitechcloud-vietnam/HiTechPay.git && cd HiTechPay
scripts/bootstrap.sh --core                 # cần config/superposition_seed.toml đã vá
NS=hitechpay; kubectl create namespace $NS

kubectl -n $NS create secret generic hitechpay-router-secrets \
  --from-literal=admin_api_key="$(openssl rand -hex 32)" \
  --from-literal=recon_admin_api_key="$(openssl rand -hex 32)" \
  --from-literal=jwt_secret="$(openssl rand -hex 32)" \
  --from-literal=master_enc_key="$(openssl rand -hex 32)" \
  --from-literal=api_key_hash_key="$(openssl rand -hex 32)"

kubectl -n $NS create configmap superposition-seed \
  --from-file=superposition_seed.toml=upstream/hyperswitch/config/superposition_seed.toml

helm repo add hyperswitch https://juspay.github.io/hyperswitch-helm && helm repo update
helm upgrade --install hitechpay hyperswitch/hyperswitch-stack -n $NS \
  -f deploy/helm/values-hitechpay.yaml --timeout 20m
```

> Sao lưu ngay secret `hitechpay-router-secrets` ra nơi an toàn (`kubectl get secret … -o yaml`):
> `master_enc_key` không thể thay đổi sau khi có dữ liệu.

Thay tên miền: sửa `api.hitechpay.vn`, `dashboard.hitechpay.vn`, `checkout.hitechpay.vn` trong
`deploy/helm/values-hitechpay.yaml` (tìm-thay toàn file) hoặc đặt trong file values riêng.

## 3.4 Production

Tạo `values-prod.yaml` (không commit secret vào đây):

```yaml
hyperswitch-app:
  server:
    run_env: production
    configs:
      connectors:
        momo: { base_url: https://payment.momo.vn/ }
        vnpay: { base_url: https://merchant.vnpay.vn/merchant_webapi/api/transaction }
        zalopay: { base_url: https://openapi.zalopay.vn/ }
        viettelmoney: { base_url: https://pay3.viettel.vn/PaymentAPI/webresources/postData }
  # PostgreSQL / Redis managed (khoá theo upstream/hyperswitch-helm/charts/hyperswitch-app/values.yaml)
  postgresql: { enabled: false }
  externalPostgresql:
    enabled: true
    primary:
      host: pg.internal.example
      auth:
        username: hyperswitch
        password: { _secretRef: { name: hitechpay-db, key: password } }
        plainpassword: { _secretRef: { name: hitechpay-db, key: password } }
        database: hyperswitch
  redis: { enabled: false }
  externalRedis:
    enabled: true
    host: redis.internal.example
    port: "6379"
hyperswitch-web:
  env:
    sdkEnv: prod
```

```bash
helm upgrade --install hitechpay hyperswitch/hyperswitch-stack -n hitechpay \
  -f deploy/helm/values-hitechpay.yaml -f values-prod.yaml --timeout 20m
```

Khuyến nghị thêm: HPA cho router, PodDisruptionBudget, NetworkPolicy chỉ cho router ra Internet (cổng thanh toán),
node pool riêng cho router, giới hạn IP egress cố định (NAT) để đăng ký với VNPAY/MoMo/ZaloPay.

## 3.5 Kiểm tra sau cài đặt

```bash
kubectl -n hitechpay get pods
kubectl -n hitechpay get ingress
curl -s https://api.hitechpay.vn/health
curl -sI https://checkout.hitechpay.vn/web/0.134.0/v1/HyperLoader.js | head -1
```

Đăng ký user đầu tiên ở `https://dashboard.<domain>` rồi làm tiếp [04-cong-thanh-toan.md](04-cong-thanh-toan.md).

## 3.6 Nâng cấp

1. Cập nhật mã: `git pull` (hoặc `scripts/upgrade-upstream.sh <repo> <tag>` khi lên bản upstream mới).
2. Đợi workflow `images.yml` build xong.
3. Nếu đổi phiên bản upstream: tăng đồng thời các `version` trong values (xem mục *Upgrading upstream* của
   [deploy/helm/README.md](../../deploy/helm/README.md)) và tạo lại ConfigMap `superposition-seed`.
4. `helm upgrade … --atomic --timeout 20m` (migration DB chạy trong job của chart). Rollback: `helm rollback hitechpay <rev>`.
