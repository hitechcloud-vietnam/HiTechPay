# Kiến trúc / Architecture

```
                ┌──────────────────────────── HiTechPay ────────────────────────────┐
 Merchant app ──┤ sdks/node, sdks/php ──► Router API (upstream/hyperswitch + patches) │
 Browser      ──┤ Checkout SDK (hyperswitch-web + patches) ──┘   │                    │
 Merchant ops ──┤ Dashboard (hyperswitch-control-center + patches)┘                    │
                └───────────────────────────────┬───────────────────────────────────┘
                                                │ connectors
     MoMo · VNPAY · ZaloPay · Viettel Money · payOS · SePay · Pay2S · 150+ global PSPs
```

Router stores data in PostgreSQL, uses Redis for caching/streams, Superposition for dynamic
config, and optionally the card vault / encryption service submodules.

## Repository layout

```
upstream/           pinned git submodules of github.com/juspay/* (never modified in place)
patches/<repo>/     HiTechPay changes as a git patch series (source of truth)
scripts/            bootstrap / apply / export / upgrade helpers
tools/              connector registration and white-label scripts
branding/           brand.json + logo assets
deploy/             docker compose stack, nginx gateway, runtime dashboard config
sdks/               merchant server SDKs (Node.js, PHP)
docs/               documentation
```

### Why patches instead of a fork?
- The parent repository pins each submodule to an **upstream** commit (`git ls-files -s upstream`).
- Local work happens on the `hitechpay` branch inside the submodule; `scripts/export-patches.sh`
  writes the commits to `patches/<repo>/` and keeps the gitlink on the upstream commit.
- `scripts/apply-patches.sh` rebuilds the branch from the pinned commit with `git am --3way`.
- Upgrading upstream = `scripts/upgrade-upstream.sh <repo> <tag>` (rebases the series).
- The patch series can be pushed to a fork (e.g. `hitechcloud-vietnam/hitechpay-core`) at any time;
  the submodule URL can then be switched without changing the workflow.

## Core changes to Hyperswitch (small and upstreamable)

1. `ConnectorSpecifications::should_build_authorize_response_locally()` — authorize uses
   `CallConnectorAction::HandleResponseWithoutBuildRequest`, so redirect gateways that sign the payment
   URL themselves (VNPAY, Viettel Money) and bank-transfer QR gateways (SePay, Pay2S) build the response
   in `handle_response` without an HTTP call.
2. `ConnectorSpecifications::should_build_psync_response_locally()` — same for PSync, for gateways with no
   status query API; the status comes from webhooks (resource object) or signed return params (`encoded_data`).

Both default to `false`; no behaviour change for existing connectors.

## Connector registration
`tools/register_connectors.py` mirrors every registry point of a reference connector (`paystack`):
enums (`common_enums`, `euclid`), routing conversions, connector mapping, validation, default trait
implementations, base URLs for every environment, `pm_filters`, dashboard connector configs
(`connector_configs/toml/*`), test scaffolding. It is idempotent.

## Payment flows

| Pattern | Connectors | Authorize | Sync | Webhook reference |
|---|---|---|---|---|
| Gateway API + redirect | MoMo, ZaloPay, payOS | HTTP create → redirect/QR | HTTP query | attempt id / order code |
| Locally signed redirect | VNPAY, Viettel Money | local URL + HMAC | VNPAY `querydr`; Viettel local | attempt id |
| Bank transfer VietQR | SePay, Pay2S | local VietQR + `HTP…` code | SePay API; Pay2S local | transfer code |
