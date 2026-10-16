#!/usr/bin/env bash
# data/, cache/ en uploads/ zijn niet anoniem te lezen. Excel-pakketten gaan
# via index.php?action=download_package, na logincheck.php.
# Run: bash tests/protected_runtime_dirs_test.sh
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
fail=0

bad() {
  echo "FAIL: $*" >&2
  fail=1
}

for dir in web/data web/data/packages web/cache web/uploads; do
  htaccess="$root/$dir/.htaccess"
  if [[ ! -f "$htaccess" ]]; then
    bad "ontbrekend $dir/.htaccess"
    continue
  fi
  rules="$(cat "$htaccess")"
  for needle in \
    "Options -Indexes" \
    "Require all denied" \
    "Deny from all" \
    "<IfModule mod_authz_core.c>" \
    "<IfModule !mod_authz_core.c>"
  do
    if [[ "$rules" != *"$needle"* ]]; then
      bad "$dir/.htaccess mist $needle"
    fi
  done
  for index in index.html index.php; do
    index_file="$root/$dir/$index"
    if [[ ! -f "$index_file" ]]; then
      bad "ontbrekend $dir/$index"
    elif [[ -s "$index_file" ]]; then
      bad "$dir/$index is niet leeg"
    fi
  done
done

index_php="$root/web/index.php"
if [[ -f "$root/web/odata.php" ]]; then
  bad "web/odata.php hoort niet in Calculus; er is geen cache_status-widget"
fi
if grep -R -n -E 'href=.*data/packages/|href=.*uploads/' "$root/web" --include='*.php' --include='*.html' >/dev/null; then
  bad "een pagina linkt direct naar data/packages of uploads"
fi
if ! grep -q "require __DIR__ . '/logincheck.php';" "$index_php"; then
  bad "index.php laadt logincheck.php niet"
fi
if ! grep -q 'session_write_close' "$index_php"; then
  bad "index.php roept session_write_close niet aan na de logincheck"
fi

python3 - "$index_php" <<'PY'
import sys
src = open(sys.argv[1], encoding="utf-8").read()
login_at = src.find("require __DIR__ . '/logincheck.php';")
release_def = src.find("function calculus_release_session_lock")
write_close = src.find("session_write_close()", release_def if release_def >= 0 else 0)
if login_at < 0 or release_def < 0 or write_close < release_def:
    sys.exit("logincheck of session_write_close ontbreekt")
if release_def < login_at:
    sys.exit("sessievrijgave staat vóór het laden van logincheck")

def section(start, end):
    i = src.find(start)
    j = src.find(end, i + len(start))
    if i < 0 or j < 0 or i < login_at:
        sys.exit("sectie ontbreekt: " + start)
    return src[i:j]

for start, end in (
    ("if ($action === 'download_excel'", "if ($action === 'confirm_apply'"),
    ("if ($action === 'download_package'", "} catch (Throwable"),
):
    block = section(start, end)
    rel = block.find("calculus_release_session_lock()")
    read = block.find("readfile(")
    if rel < 0 or read < 0 or rel > read:
        sys.exit("session_write_close moet vóór readfile in " + start)
    if "login/lib.php" in block:
        sys.exit("download hoort de bestaande logincheck te gebruiken, niet een tweede include")
PY

workflow="$root/.github/workflows/deploy-ftp.yml"
line_of() {
  grep -n -F -e "$1" "$workflow" | head -1 | cut -d: -f1
}
must_after() {
  local later="$1"
  local earlier="$2"
  local later_n earlier_n
  later_n="$(line_of "$later")"
  earlier_n="$(line_of "$earlier")"
  if [[ -z "$later_n" || -z "$earlier_n" ]]; then
    bad "ftp-patroon ontbreekt: $later of $earlier"
    return
  fi
  if [[ "$later_n" -lt "$earlier_n" ]]; then
    bad "$later moet ná $earlier staan, anders uploadt lftp hem niet"
  fi
}

for include in \
  "--include-glob data/.htaccess" \
  "--include-glob data/index.html" \
  "--include-glob data/index.php" \
  "--include-glob data/packages/.htaccess" \
  "--include-glob data/packages/index.html" \
  "--include-glob data/packages/index.php" \
  "--include-glob cache/.htaccess" \
  "--include-glob cache/index.html" \
  "--include-glob cache/index.php" \
  "--include-glob uploads/.htaccess" \
  "--include-glob uploads/index.html" \
  "--include-glob uploads/index.php"
do
  must_after "$include" "--exclude-glob .htaccess"
  must_after "$include" "--exclude-glob data/**"
  must_after "$include" "--exclude-glob cache/**"
  must_after "$include" "--exclude-glob uploads/**"
done

for needle in \
  "--exclude-glob auth.php" \
  "--exclude-glob .htaccess" \
  "--exclude-glob .htpasswd" \
  "--exclude-glob data/**" \
  "--exclude-glob data/packages/**" \
  "--exclude-glob cache/**" \
  "--exclude-glob uploads/**" \
  "--include-glob data/" \
  "--include-glob data/packages/" \
  "--include-glob cache/" \
  "--include-glob uploads/"
do
  if ! grep -q -F -e "$needle" "$workflow"; then
    bad "ftp-deploy mist $needle"
  fi
done

if grep -E -- '--include-glob .*(sqlite|\.xlsx|\.json|\*\*)' "$workflow" >/dev/null; then
  bad "ftp-deploy include een runtime-bestand of een hele mapinhoud"
fi
if grep -q -F -- '--delete-excluded' "$workflow"; then
  bad "ftp-deploy zou uitgesloten serverdata verwijderen"
fi

tracked_ok=(
  web/data/.htaccess
  web/data/index.html
  web/data/index.php
  web/data/packages/.htaccess
  web/data/packages/index.html
  web/data/packages/index.php
  web/cache/.htaccess
  web/cache/index.html
  web/cache/index.php
  web/uploads/.htaccess
  web/uploads/index.html
  web/uploads/index.php
)
for path in "${tracked_ok[@]}"; do
  if git -C "$root" check-ignore -q -- "$path"; then
    bad "gitignore sluit $path uit, de FTP-job kan hem dan niet uit git halen"
  fi
done

ignored_required=(
  web/auth.php
  web/data/calculus.sqlite
  web/data/calculus.sqlite-wal
  web/data/packages/PRJ1_20260101.xlsx
  web/cache/bc-companies.json
  web/uploads/20260101_secret.xlsx
)
for path in "${ignored_required[@]}"; do
  if ! git -C "$root" check-ignore -q -- "$path"; then
    bad "gitignore laat runtimebestand toe: $path"
  fi
done

if [[ "$fail" -ne 0 ]]; then
  echo "protected runtime dir tests failed" >&2
  exit 1
fi

echo "OK protected runtime dirs"
