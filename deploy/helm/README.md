# HiTechPay on Kubernetes (Helm)

HiTechPay reuses the upstream umbrella chart `hyperswitch-stack`
([`upstream/hyperswitch-helm/charts/hyperswitch-stack`](../../upstream/hyperswitch-helm/charts/hyperswitch-stack),
published at `https://juspay.github.io/hyperswitch-helm`) and only overrides values:
[`values-hitechpay.yaml`](values-hitechpay.yaml).

| Component | Image (GHCR, built by `.github/workflows/images.yml`) | Public host |
|---|---|---|
| Router (API) | `ghcr.io/hitechcloud-vietnam/hitechpay-router:v1.127.0` | `api.hitechpay.vn` |
| Scheduler consumer + producer | `ghcr.io/hitechcloud-vietnam/hitechpay-scheduler:v1.127.0` | – |
| Dashboard | `ghcr.io/hitechcloud-vietnam/hitechpay-dashboard:v1.38.9` | `dashboard.hitechpay.vn` |
| Checkout web SDK | `ghcr.io/hitechcloud-vietnam/hitechpay-checkout-sdk:v0.134.0` | `checkout.hitechpay.vn` (`/HyperLoader.js`) |
| Drainer, card vault | upstream images (no HiTechPay changes) | – |

What the override sets:
- HiTechPay images. Each one is tagged with the upstream release it is patched on, because the chart
  reuses those versions: the DB migration job downloads `juspay/hyperswitch` at `services.router.version`,
  and the SDK is served from `/web/<gitVersion>/v1/`. CI also pushes `sha-<commit>`, `main`/`latest` and
  `v*` release tags.
- Ingress (`ingressClassName: nginx`, cert-manager `letsencrypt-prod`, TLS) for the three hosts above.
- Base URLs for the 7 Vietnamese connectors (sandbox by default). The chart's bundled router config does not
  include them, and the router refuses to start when a connector has an empty `base_url`.
- Router secrets read from the `hitechpay-router-secrets` Secret, so the chart's `test_admin` defaults are not used.
- The patched Superposition seed, which includes the VN connectors, read from a ConfigMap.
- Brand settings: e-mail theme, payout/collect links, TOTP issuer, and dashboard `config` env overrides
  pinned to [`deploy/config/dashboard.toml`](../config/dashboard.toml). The dashboard image already contains
  the theme from [`dashboard_theme.json`](../config/dashboard_theme.json).
- Monitoring is off. Turn it on with `--set hyperswitch-monitoring.enabled=true`.

## Prerequisites
- Kubernetes 1.24+, Helm 3.12+, an ingress-nginx controller, cert-manager with a `letsencrypt-prod` ClusterIssuer
  (or change `className` / annotations / `tls` in the values file).
- DNS `api.`, `dashboard.`, `checkout.hitechpay.vn` pointing at the ingress.
- The four `hitechpay-*` GHCR packages must be **public**. The router chart has no `imagePullSecrets`.

## Install

```bash
NS=hitechpay
kubectl create namespace $NS

# 1. Secrets (keep them; master_enc_key cannot change once data is written)
kubectl -n $NS create secret generic hitechpay-router-secrets \
  --from-literal=admin_api_key="$(openssl rand -hex 32)" \
  --from-literal=recon_admin_api_key="$(openssl rand -hex 32)" \
  --from-literal=jwt_secret="$(openssl rand -hex 32)" \
  --from-literal=master_enc_key="$(openssl rand -hex 32)" \
  --from-literal=api_key_hash_key="$(openssl rand -hex 32)"

# 2. Superposition seed with the Vietnamese connectors (run scripts/bootstrap.sh --core first)
kubectl -n $NS create configmap superposition-seed \
  --from-file=superposition_seed.toml=upstream/hyperswitch/config/superposition_seed.toml

# 3a. Published chart
helm repo add hyperswitch https://juspay.github.io/hyperswitch-helm
helm repo update
helm upgrade --install hitechpay hyperswitch/hyperswitch-stack \
  -n $NS -f deploy/helm/values-hitechpay.yaml --timeout 20m

# 3b. ...or the chart sources pinned in this repo
git submodule update --init --depth 1 upstream/hyperswitch-helm
helm dependency build upstream/hyperswitch-helm/charts/hyperswitch-stack
helm upgrade --install hitechpay upstream/hyperswitch-helm/charts/hyperswitch-stack \
  -n $NS -f deploy/helm/values-hitechpay.yaml --timeout 20m
```

Preview the manifests without installing:

```bash
helm template hitechpay hyperswitch/hyperswitch-stack -n hitechpay -f deploy/helm/values-hitechpay.yaml > /tmp/hitechpay.yaml
```

### Production
Put environment-specific settings in a second values file, for example `values-prod.yaml`:

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
hyperswitch-web:
  env:
    sdkEnv: prod
```

Then install with `-f deploy/helm/values-hitechpay.yaml -f values-prod.yaml`. Also replace the in-cluster
PostgreSQL and Redis with managed services (`externalPostgresql` / `externalRedis` in the `hyperswitch-app`
chart), and set `entity_logo_url` to a real PNG.

## After install
1. Create the first dashboard user. Either sign up at `https://dashboard.hitechpay.vn`, or run
   `upstream/hyperswitch/scripts/create_default_user.sh` with
   `HYPERSWITCH_SERVER_URL=https://api.hitechpay.vn`.
2. Configure the VN connectors and webhooks as described in [docs/deployment.md](../../docs/deployment.md#sau-khi-chạy).

## Upgrading upstream
After `scripts/upgrade-upstream.sh`, bump the versions together:
- `services.{router,consumer,producer,drainer}.version`
- `hyperswitch-web.autoBuild.gitCloneParam.gitVersion`, `services.sdk.version` and
  `hyperswitch-control-center.dependencies.sdk.version`
- `hyperswitch-control-center.image.tag`

Then recreate the `superposition-seed` ConfigMap.

## Validation
The override was rendered against the chart sources in `upstream/hyperswitch-helm` (stack 0.2.28,
hyperswitch-app 1.3.4) with `helm template` and `helm lint`. Third-party sub-charts (bitnami, vector, mailhog,
superposition) were replaced by empty stubs because their repositories could not be reached offline.
