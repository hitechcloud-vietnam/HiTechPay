#!/usr/bin/env bash
# Move a patched submodule to a new upstream tag and rebase the HiTechPay patches.
#
#   scripts/upgrade-upstream.sh hyperswitch v1.128.0
source "$(dirname "$0")/lib.sh"

repo="${1:?repo name, e.g. hyperswitch}"
tag="${2:?upstream tag}"
dir="$ROOT_DIR/upstream/$repo"

git -C "$dir" fetch --depth 50 origin "refs/tags/$tag:refs/tags/$tag"
new_base="$(git -C "$dir" rev-parse "$tag^{commit}")"
git -C "$dir" rebase --onto "$new_base" "$(pinned_commit "$repo")" "$BRANCH" || {
  warn "resolve conflicts in upstream/$repo, then: git -C upstream/$repo rebase --continue"
  warn "afterwards re-run: scripts/upgrade-upstream.sh is NOT needed, run:"
  warn "  echo $new_base > patches/$repo/BASE && scripts/export-patches.sh"
  exit 1
}
git -C "$ROOT_DIR" update-index --cacheinfo "160000,$new_base,upstream/$repo"
echo "$new_base" >"$PATCH_DIR/$repo/BASE"
"$ROOT_DIR/scripts/export-patches.sh"
log "$repo upgraded to $tag; review and commit patches/$repo and the submodule pointer"
