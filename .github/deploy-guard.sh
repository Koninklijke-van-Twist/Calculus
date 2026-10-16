#!/usr/bin/env bash
# Deploy-guard: draait het lftp-mirrorcommando uit deploy-ftp.yml tegen een nep-servermap
# (file://) met dummybestanden op alle beschermde paden uit .github/deploy-protected.txt.
# Faalt als een beschermd bestand verdwijnt of wordt overschreven.
set -euo pipefail
root=$(cd "$(dirname "$0")/.." && pwd)
wf="${1:-$root/.github/workflows/deploy-ftp.yml}"
list="$root/.github/deploy-protected.txt"
fake=$(mktemp -d)
trap 'rm -rf "$fake"' EXIT

mk() { mkdir -p "$fake/$(dirname "$1")"; printf 'GUARD %s\n' "$1" > "$fake/$1"; echo "$1" >> "$fake.paths"; }
: > "$fake.paths"
while IFS= read -r p || [ -n "$p" ]; do
  p="${p%%#*}"; p="$(echo "$p" | tr -d '\r' | xargs)"; [ -z "$p" ] && continue
  case "$p" in
    */\*\*) b="${p%/**}"; mk "$b/guard_dummy.dat"; mk "$b/guard_sub/guard_dummy.dat";;
    */\*/)  b="${p%/*/}"; mk "$b/guard_sub/guard_dummy.dat";;
    */)     mk "${p}guard_dummy.dat"; mk "${p}guard_sub/guard_dummy.dat";;
    *\**)   mk "${p//\*/guard_dummy}";;
    *)      mk "$p";;
  esac
  # Een basenaam zonder pad (bv. .htaccess) geldt in lftp op elk niveau.
  case "$p" in */*|*\**) ;; *) for d in "$root"/web/*/; do [ -d "$d" ] && mk "$(basename "$d")/$p"; done;; esac
done < "$list"

mirror=$(tr -d '\r' < "$wf" | sed -n '/mirror -R/,/[^\\]$/p' | sed 's/^ *//' | sed -e ':a' -e '/\\$/N; s/\\\n *//; ta' | head -1)
[ -n "$mirror" ] || { echo "Geen mirror-commando gevonden in $wf"; exit 1; }
echo "Mirror: $mirror"
(cd "$root" && lftp -c "set cmd:fail-exit true; open file://$fake; $mirror")

fail=0
while IFS= read -r f; do
  if [ ! -f "$fake/$f" ]; then echo "::error::Beschermd pad zou VERWIJDERD worden: $f"; fail=1
  elif [ "$(cat "$fake/$f")" != "GUARD $f" ]; then echo "::error::Beschermd pad zou OVERSCHREVEN worden: $f"; fail=1; fi
done < <(sort -u "$fake.paths")
n=$(sort -u "$fake.paths" | wc -l); rm -f "$fake.paths"
[ $fail -eq 0 ] || exit 1
echo "Deploy-guard OK: $n beschermde paden intact."
