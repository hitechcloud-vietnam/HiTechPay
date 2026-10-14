# White-label

Brand source: [`branding/brand.json`](../branding/brand.json) and `branding/assets/*.svg`.

```bash
python3 tools/whitelabel.py                 # apply to dashboard + web SDK
python3 tools/whitelabel.py --only web      # or control-center
python3 tools/whitelabel.py --dry-run -v    # preview every rewritten string
```

Then commit inside the submodules (`hitechpay` branch) and run `scripts/export-patches.sh`.

## What is rebranded
- **Dashboard** (`hyperswitch-control-center`): logo / favicon (`public/hyperswitch/assets/hitechpay/`),
  `config/config.toml`, `config/theme.json`, built-in fallback theme, login / invite screens, page titles,
  ~70 user-visible strings ("Hyperswitch" → "HiTechPay", "Hyperswitch Control Center" → "HiTechPay Dashboard"),
  docs links → `docs.hitechpay.vn`.
- **Checkout SDK** (`hyperswitch-web`): HTML titles, "Powered by HiTechPay" badge, mandate texts and
  `poweredBy` strings in every locale, Vietnamese locale (`vi`, `vi-VN`).
- **Runtime** (no rebuild): `deploy/config/dashboard.toml` and `deploy/config/dashboard_theme.json` are mounted
  into the dashboard container (`configPath` / `themeConfigPath`).

## Intentionally unchanged (compatibility)
JS globals (`window.Hyper`, `HyperLoader.js`), postMessage event names, CSS classes, API paths, module and
package names, upstream API hosts, the BECS (Australia) mandate legal entity.

## Review before production
- Compliance texts (PCI DSS claims, Apple Pay template e-mail) now name HiTechPay — confirm with legal.
- E-mail logo (`entity_logo_url`) must be an absolute PNG URL on your domain.
- Playwright (dashboard) assertions expect the HiTechPay strings. E-mail subjects come from the router's
  `theme.email_config.entity_name` and the Cypress demo app ("Hyperswitch Unified Checkout") is not rebranded,
  so those assertions are unchanged. Visual-regression snapshots (`playwright-tests/visual-testing`) need regenerating.
