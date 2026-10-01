#!/usr/bin/env bash
# Stop de FTP-deploy als FTP_REMOTE_DIR leeg is of geen app-map onder /var/www/html/.
# Verwacht secret (Tim zet het exacte pad): FTP_REMOTE_DIR=/var/www/html/calculus
set -euo pipefail

fail() {
  echo "FTP_REMOTE_DIR geweigerd: $1" >&2
  echo "Verwacht een pad zoals /var/www/html/calculus." >&2
  echo "Leeg, de documentroot zelf, '..' en paden buiten /var/www/html/ zijn onveilig." >&2
  exit 1
}

remote="${FTP_REMOTE_DIR-}"

if [[ -z "${remote//[[:space:]]/}" ]]; then
  fail "de variabele is leeg of niet gezet."
fi

if [[ "$remote" =~ [[:cntrl:]] ]]; then
  fail "de waarde bevat een stuurteken."
fi

remote="${remote%/}"

if [[ ! "$remote" =~ ^/var/www/html/[A-Za-z0-9._/-]+$ ]]; then
  fail "het pad moet onder /var/www/html/ liggen (alleen letters, cijfers, . _ - en /)."
fi

if [[ "$remote" == *//* || "$remote" == *..* || "$remote" == */.* || "$remote" == *"/." ]]; then
  fail "het pad bevat een leeg segment, '.' of '..'."
fi

rest="${remote#/var/www/html/}"
if [[ -z "$rest" || "$rest" == "/" ]]; then
  fail "het pad mag niet de documentroot /var/www/html zelf zijn."
fi

echo "FTP_REMOTE_DIR is veilig: ${remote}"
