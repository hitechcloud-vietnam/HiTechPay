#!/usr/bin/env bash
# Export the commits of each submodule's `hitechpay` branch (on top of the pinned upstream
# commit) into patches/<repo>/. Run after committing changes inside a submodule.
source "$(dirname "$0")/lib.sh"

for repo in "${PATCHED_REPOS[@]}"; do
  dir="$ROOT_DIR/upstream/$repo"
  [[ -e "$dir/.git" ]] || { warn "skip $repo (not initialised)"; continue; }
  base="$(pinned_commit "$repo")"
  count="$(git -C "$dir" rev-list --count "$base..$BRANCH" 2>/dev/null || echo 0)"
  find "$PATCH_DIR/$repo" -name '*.patch' -delete 2>/dev/null || true
  if [[ "$count" == "0" ]]; then
    warn "$repo: no commits on $BRANCH"
    continue
  fi
  mkdir -p "$PATCH_DIR/$repo"
  echo "$base" >"$PATCH_DIR/$repo/BASE"
  git -C "$dir" format-patch -q --zero-commit --no-signature --no-stat -o "$PATCH_DIR/$repo" "$base..$BRANCH"
  log "$repo: exported $count patch(es)"
done

# Keep the parent repository pinned to the upstream commits, never to local commits.
for repo in "${PATCHED_REPOS[@]}"; do
  base="$(pinned_commit "$repo")"
  [[ -n "$base" ]] && git -C "$ROOT_DIR" update-index --cacheinfo "160000,$base,upstream/$repo"
done
