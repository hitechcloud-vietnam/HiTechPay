#!/usr/bin/env bash
# Re-create the `hitechpay` branch of every patched submodule from its pinned upstream
# commit and apply patches/<repo>/*.patch with `git am`.
#
#   scripts/apply-patches.sh                 # every patched repo (must all be initialised)
#   scripts/apply-patches.sh hyperswitch     # only the named repo(s), e.g. in CI
source "$(dirname "$0")/lib.sh"

repos=("$@")
[[ ${#repos[@]} -gt 0 ]] || repos=("${PATCHED_REPOS[@]}")

for repo in "${repos[@]}"; do
  [[ " ${PATCHED_REPOS[*]} " == *" $repo "* ]] || die "unknown patched repo: $repo (expected one of: ${PATCHED_REPOS[*]})"
  dir="$ROOT_DIR/upstream/$repo"
  patches=("$PATCH_DIR/$repo"/*.patch)
  [[ -d "$dir/.git" || -f "$dir/.git" ]] || die "upstream/$repo is not initialised, run scripts/bootstrap.sh"
  [[ -e "${patches[0]}" ]] || { warn "no patches for $repo"; continue; }
  base="$(pinned_commit "$repo")"
  ensure_identity "$dir"
  if ! git -C "$dir" cat-file -e "$base^{commit}" 2>/dev/null; then
    log "fetching pinned commit $base for $repo"
    git -C "$dir" fetch --depth 1 origin "$base"
  fi
  log "applying ${#patches[@]} patch(es) to $repo on top of ${base:0:10}"
  git -C "$dir" am --abort >/dev/null 2>&1 || true
  git -C "$dir" checkout -q -B "$BRANCH" "$base"
  git -C "$dir" am -q --3way "${patches[@]}"
done
log "patches applied"
