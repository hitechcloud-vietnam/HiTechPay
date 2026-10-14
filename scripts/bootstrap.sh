#!/usr/bin/env bash
# Fetch all upstream submodules (shallow) and apply HiTechPay patches.
#
#   scripts/bootstrap.sh            # all submodules
#   scripts/bootstrap.sh --core     # only the repos HiTechPay patches (faster)
source "$(dirname "$0")/lib.sh"

cd "$ROOT_DIR" || exit 1
if [[ "${1:-}" == "--core" ]]; then
  for repo in "${PATCHED_REPOS[@]}"; do
    log "fetching upstream/$repo"
    git submodule update --init --depth 1 "upstream/$repo"
  done
else
  log "fetching all upstream submodules (shallow)"
  git submodule update --init --depth 1 --jobs 4
fi

"$ROOT_DIR/scripts/apply-patches.sh"
log "done. Next: scripts/build.sh or deploy/README.md"
