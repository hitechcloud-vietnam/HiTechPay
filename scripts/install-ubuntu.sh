#!/usr/bin/env bash
# One-shot installer for a single HiTechPay server (Ubuntu 22.04 / 24.04).
#
#   sudo DOMAIN=hitechpay.vn ACME_EMAIL=admin@hitechpay.vn scripts/install-ubuntu.sh
#
# Steps (each is idempotent, safe to re-run):
#   1. system packages + Docker Engine / Compose v2
#   2. clone or update the repository in $INSTALL_DIR (default /opt/hitechpay)
#   3. bootstrap upstream submodules + HiTechPay patches, prepare Docker build context
#   4. generate deploy/.env with random secrets (only if it does not exist yet)
#   5. build images (or pull from GHCR with USE_GHCR=1) and start the stack
#   6. nginx reverse proxy + Let's Encrypt for api./dashboard./checkout.$DOMAIN (skip: NO_TLS=1)
#   7. systemd unit, daily database backup, firewall
#
# Environment variables:
#   DOMAIN        base domain (api.$DOMAIN, dashboard.$DOMAIN, checkout.$DOMAIN)   [required]
#   ACME_EMAIL    e-mail for Let's Encrypt                                            [required unless NO_TLS=1]
#   INSTALL_DIR   default /opt/hitechpay
#   REPO_URL      default https://github.com/hitechcloud-vietnam/HiTechPay.git
#   REPO_REF      default main
#   RUN_ENV       sandbox (default) | production
#   USE_GHCR=1    pull prebuilt images from ghcr.io instead of building
#   NO_TLS=1      skip nginx/certbot (e.g. behind an existing load balancer)
#   NO_FIREWALL=1 do not touch ufw
set -euo pipefail

: "${DOMAIN:?set DOMAIN, e.g. DOMAIN=hitechpay.vn}"
INSTALL_DIR="${INSTALL_DIR:-/opt/hitechpay}"
REPO_URL="${REPO_URL:-https://github.com/hitechcloud-vietnam/HiTechPay.git}"
REPO_REF="${REPO_REF:-main}"
RUN_ENV="${RUN_ENV:-sandbox}"
API_HOST="api.$DOMAIN"; DASHBOARD_HOST="dashboard.$DOMAIN"; CHECKOUT_HOST="checkout.$DOMAIN"

log() { printf '\033[1;34m[install]\033[0m %s\n' "$*"; }
die() { printf '\033[1;31m[install]\033[0m %s\n' "$*" >&2; exit 1; }
[[ $EUID -eq 0 ]] || die "run as root (sudo)"
if [[ "${NO_TLS:-0}" != "1" ]]; then : "${ACME_EMAIL:?set ACME_EMAIL for the TLS certificate, or NO_TLS=1}"; fi

log "1/7 system packages"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq git curl ca-certificates gnupg python3 jq openssl cron >/dev/null
if ! command -v docker >/dev/null; then
  curl -fsSL https://get.docker.com | sh
fi
docker compose version >/dev/null || die "docker compose v2 is required"

log "2/7 repository → $INSTALL_DIR ($REPO_REF)"
if [[ -d "$INSTALL_DIR/.git" ]]; then
  git -C "$INSTALL_DIR" fetch -q origin "$REPO_REF"
  git -C "$INSTALL_DIR" checkout -q "$REPO_REF"
  git -C "$INSTALL_DIR" merge -q --ff-only "origin/$REPO_REF"
else
  git clone -q --branch "$REPO_REF" "$REPO_URL" "$INSTALL_DIR"
fi
cd "$INSTALL_DIR"

log "3/7 upstream submodules + patches"
scripts/bootstrap.sh --core
scripts/prepare-docker-context.sh

log "4/7 deploy/.env"
if [[ ! -f deploy/.env ]]; then
  cp deploy/.env.example deploy/.env
  set_env() { sed -i "s|^$1=.*|$1=$2|" deploy/.env; }
  set_env HTP_RUN_ENV "$RUN_ENV"
  set_env HTP_API_PUBLIC_URL "https://$API_HOST"
  set_env HTP_DASHBOARD_PUBLIC_URL "https://$DASHBOARD_HOST"
  set_env HTP_ADMIN_API_KEY "$(openssl rand -hex 32)"
  set_env HTP_JWT_SECRET "$(openssl rand -hex 32)"
  set_env HTP_MASTER_ENC_KEY "$(openssl rand -hex 32)"
  set_env HTP_DB_PASSWORD "$(openssl rand -hex 24)"
  chmod 600 deploy/.env
  log "generated deploy/.env with random secrets — BACK IT UP (it holds the master encryption key)"
else
  log "deploy/.env exists, keeping it"
fi
# dashboard runtime config points at the public hosts
sed -i "s|^api_url=.*|api_url=\"https://$API_HOST\"|; s|^sdk_url=.*|sdk_url=\"https://$CHECKOUT_HOST/HyperLoader.js\"|" \
  deploy/config/dashboard.toml

log "5/7 images + stack"
COMPOSE=(docker compose -f deploy/docker-compose.yml -f deploy/docker-compose.prod.yml)
if [[ "${USE_GHCR:-0}" == "1" ]]; then
  COMPOSE+=(-f deploy/docker-compose.ghcr.yml)
  "${COMPOSE[@]}" --env-file deploy/.env --profile scheduler pull
else
  "${COMPOSE[@]}" --env-file deploy/.env build
fi
"${COMPOSE[@]}" --env-file deploy/.env --profile scheduler up -d
printf '%s ' "${COMPOSE[@]}" > deploy/.compose-cmd   # reused by systemd, backup and smoke-test scripts

log "waiting for the router health check"
for _ in $(seq 1 60); do
  curl -fsS http://127.0.0.1:8080/health >/dev/null 2>&1 && break
  sleep 5
done
curl -fsS http://127.0.0.1:8080/health >/dev/null || die "router is not healthy: ${COMPOSE[*]} logs router"

if [[ "${NO_TLS:-0}" != "1" ]]; then
  log "6/7 nginx + Let's Encrypt"
  apt-get install -y -qq nginx certbot python3-certbot-nginx >/dev/null
  sed -e "s/__API_HOST__/$API_HOST/; s/__DASHBOARD_HOST__/$DASHBOARD_HOST/; s/__CHECKOUT_HOST__/$CHECKOUT_HOST/" \
    deploy/nginx/host-nginx.conf.template > /etc/nginx/sites-available/hitechpay.conf
  ln -sf /etc/nginx/sites-available/hitechpay.conf /etc/nginx/sites-enabled/hitechpay.conf
  rm -f /etc/nginx/sites-enabled/default
  nginx -t && systemctl reload nginx
  certbot --nginx --non-interactive --agree-tos --redirect -m "$ACME_EMAIL" \
    -d "$API_HOST" -d "$DASHBOARD_HOST" -d "$CHECKOUT_HOST"
else
  log "6/7 TLS skipped (NO_TLS=1)"
fi

log "7/7 systemd, backups, firewall"
cat > /etc/systemd/system/hitechpay.service <<UNIT
[Unit]
Description=HiTechPay Platform
Requires=docker.service
After=docker.service network-online.target

[Service]
Type=oneshot
RemainAfterExit=yes
WorkingDirectory=$INSTALL_DIR
ExecStart=/bin/sh -c '\$(cat deploy/.compose-cmd) --env-file deploy/.env --profile scheduler up -d'
ExecStop=/bin/sh -c '\$(cat deploy/.compose-cmd) --env-file deploy/.env --profile scheduler down'
TimeoutStartSec=0

[Install]
WantedBy=multi-user.target
UNIT
systemctl daemon-reload
systemctl enable hitechpay >/dev/null

mkdir -p /var/backups/hitechpay
cat > /etc/cron.d/hitechpay-backup <<CRON
15 2 * * * root cd $INSTALL_DIR && BACKUP_DIR=/var/backups/hitechpay KEEP_DAYS=14 scripts/backup-db.sh >> /var/log/hitechpay-backup.log 2>&1
CRON

if [[ "${NO_FIREWALL:-0}" != "1" ]] && command -v ufw >/dev/null; then
  ufw allow 22/tcp >/dev/null; ufw allow 80/tcp >/dev/null; ufw allow 443/tcp >/dev/null
  ufw --force enable >/dev/null
fi

log "done."
log "  API        https://$API_HOST/health"
log "  Dashboard  https://$DASHBOARD_HOST"
log "  SDK        https://$CHECKOUT_HOST/HyperLoader.js"
log "next: scripts/smoke-test.sh && scripts/bootstrap-merchant.sh (docs/trien-khai/00-khoi-dong-nhanh.md)"
