#!/usr/bin/env bash
# Docker builds of the patched submodules need a self-contained .git directory: a submodule's
# `.git` is normally a file pointing into the parent's .git/modules, which is not part of the
# build context (the router reads git info at compile time, the web SDK Dockerfile runs
# `git submodule update`). This moves each submodule's git dir into the submodule.
# Safe to re-run; git keeps working with embedded submodule git dirs.
#
#   scripts/prepare-docker-context.sh            # all patched repos
#   scripts/prepare-docker-context.sh hyperswitch-web
source "$(dirname "$0")/lib.sh"

repos=("$@")
[[ ${#repos[@]} -gt 0 ]] || repos=("${PATCHED_REPOS[@]}")
for repo in "${repos[@]}"; do
  dir="$ROOT_DIR/upstream/$repo"
  if [[ -f "$dir/.git" ]]; then
    gitdir="$(git -C "$dir" rev-parse --absolute-git-dir)"
    rm "$dir/.git"
    mv "$gitdir" "$dir/.git"
    git config --file "$dir/.git/config" --unset core.worktree || true
    log "$repo: git dir moved into the submodule"
  fi
done
