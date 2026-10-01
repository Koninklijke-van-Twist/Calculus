#!/usr/bin/env bash
# Verwacht: FTP_REMOTE_DIR=/var/www/html/calculus is veilig; lege en wilde paden niet.
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
guard="$root/scripts/guard-ftp-remote-dir.sh"
fail=0

expect_fail() {
  local value="$1"
  local name="$2"
  set +e
  FTP_REMOTE_DIR="$value" bash "$guard" >/tmp/calculus-guard-out 2>/tmp/calculus-guard-err
  local code=$?
  set -e
  if [[ "$code" -eq 0 ]]; then
    echo "FAIL $name (exit 0)" >&2
    fail=1
  else
    echo "OK  $name"
  fi
}

expect_ok() {
  local value="$1"
  local name="$2"
  if FTP_REMOTE_DIR="$value" bash "$guard" >/tmp/calculus-guard-out; then
    echo "OK  $name"
  else
    echo "FAIL $name" >&2
    fail=1
  fi
}

expect_fail "" "empty"
expect_fail "   " "whitespace"
expect_fail "/var/www/html" "document root"
expect_fail "/var/www/html/" "document root slash"
expect_fail "/tmp/calculus" "outside tree"
expect_fail "/var/www/calculus" "missing html segment"
expect_fail "/var/www/html/../etc" "dotdot"
expect_fail "/var/www/html/calculus/../../vulcanus" "nested dotdot"
expect_fail $'/var/www/html/calculus\n' "newline"
expect_fail "/var/www/html/calculus;rm" "shell metacharacter"
expect_fail "/var/www/html//calculus" "empty segment"

expect_ok "/var/www/html/calculus" "expected path"
expect_ok "/var/www/html/calculus/" "trailing slash"
expect_ok "/var/www/html/apps/calculus" "nested app dir"

set +e
env -u FTP_REMOTE_DIR bash "$guard" >/tmp/calculus-guard-out 2>/tmp/calculus-guard-err
code=$?
set -e
if [[ "$code" -eq 0 ]]; then
  echo "FAIL unset variable" >&2
  fail=1
else
  echo "OK  unset variable"
fi

if [[ "$fail" -ne 0 ]]; then
  echo "guard tests failed" >&2
  exit 1
fi

echo "all passed"
