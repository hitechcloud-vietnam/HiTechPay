#!/usr/bin/env bash
# Shared helpers for HiTechPay scripts.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PATCH_DIR="$ROOT_DIR/patches"
BRANCH="hitechpay"

# Upstream repositories that carry HiTechPay patches (directory names under upstream/)
PATCHED_REPOS=(hyperswitch hyperswitch-web hyperswitch-control-center hyperswitch-client-core hyperswitch-woocommerce-plugin)

log() { printf '\033[1;34m[hitechpay]\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[hitechpay]\033[0m %s\n' "$*" >&2; }
die() { printf '\033[1;31m[hitechpay]\033[0m %s\n' "$*" >&2; exit 1; }

# Upstream base commit of a patched submodule: patches/<repo>/BASE, falling back to the
# commit pinned in the parent repository index.
pinned_commit() {
  local repo="$1"
  if [[ -f "$PATCH_DIR/$repo/BASE" ]]; then
    tr -d '[:space:]' <"$PATCH_DIR/$repo/BASE"
  else
    git -C "$ROOT_DIR" ls-files -s "upstream/$repo" | awk '{print $2}'
  fi
}

# `git am` needs a committer identity; CI runners have none configured.
ensure_identity() {
  local dir="$1"
  if ! git -C "$dir" config user.name >/dev/null; then
    git -C "$dir" config user.name "${GIT_AUTHOR_NAME:-thanhan92-f1}"
  fi
  if ! git -C "$dir" config user.email >/dev/null; then
    git -C "$dir" config user.email "${GIT_AUTHOR_EMAIL:-thanhan92-f1@users.noreply.github.com}"
  fi
}
