#!/usr/bin/env bash
# Push Calculus to GitHub using workspace SSH key via SOCKS5 (Cursor sandbox).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
export GIT_SSH_COMMAND="ssh -F ${ROOT}/../.ssh/config"
cd "$ROOT"
git push -u origin master "$@"
