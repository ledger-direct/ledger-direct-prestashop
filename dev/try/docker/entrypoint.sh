#!/usr/bin/env bash
#
# Every start of the try-it shop:
# - first start: load Flashlight's pre-installed shop into the stack's database, install the module,
#   German as the second language, the demo login, free shipping, the other payment methods off
# - apply the LedgerDirect settings (receiving account, payment methods, the shop's address) — on
#   every start, so a changed LD_TESTNET_ACCOUNT or LD_TRY_PORT takes effect without a reset
# - when the image brings a new catalogue: the demo article, the miniatures, the home page
# - after an image update: the module's upgrade
# - then the LedgerDirect cron in the background, and the shop on Flashlight's nginx and PHP-FPM
#
# The shop's state lives in the stack's volumes (database, img/, /var/lib/ledger-direct), so
# recreating this container keeps it. `docker compose down -v` is the reset.
set -euo pipefail
cd /var/www/html

: "${LD_DOMAIN:=localhost:8089}"
: "${LD_DB_HOST:=db}"
: "${LD_DB_NAME:=prestashop}"
: "${LD_DB_USER:=prestashop}"
: "${LD_DB_PASSWORD:=prestashop}"
# A demo login for a shop that only listens on this machine. Not a secret, never reuse it.
# PrestaShop's back office logs in with an e-mail address.
: "${LD_ADMIN_EMAIL:=demo@example.com}"
: "${LD_ADMIN_PASSWORD:=demo-ledgerdirect-1}"
export LD_DOMAIN LD_DB_HOST LD_DB_NAME LD_DB_USER LD_DB_PASSWORD LD_ADMIN_EMAIL LD_ADMIN_PASSWORD

STATE=/var/lib/ledger-direct
INSTALLING=$STATE/installing        # a first start began: the database may hold a half-set-up shop
INSTALLED=$STATE/installed          # the first start completed
CATALOG=$STATE/catalog              # the catalogue (catalog-id) last imported
BUILD=$STATE/build                  # the image the shop was last set up with
VERSION=$STATE/prestashop-version   # the PrestaShop the database belongs to
SETUP=/usr/local/share/ledger-direct
log() { printf '[ledger-direct-try] %s\n' "$*"; }

sql() { mariadb --host="$LD_DB_HOST" --user="$LD_DB_USER" --password="$LD_DB_PASSWORD" "$LD_DB_NAME" "$@"; }
wait_for_db() {
  local tries=120
  until sql -e 'SELECT 1' >/dev/null 2>&1; do
    tries=$((tries - 1))
    if [ "$tries" -le 0 ]; then log "gave up waiting for the database"; exit 1; fi
    sleep 2
  done
}
db_has_shop() { [ -n "$(sql -N -e "SHOW TABLES LIKE 'ps_configuration'")" ]; }
# PrestaShop's bundled marketing modules raise deprecation notices on PHP 8.3 at every boot.
php() { command php -d 'error_reporting=E_ALL & ~E_DEPRECATED' "$@"; }
console() { php -d memory_limit=-1 bin/console "$@"; }

wait_for_db
started=$(date +%s)

if [ -f "$INSTALLED" ] && ! db_has_shop; then
  # The database was removed but not the shop's own volume: nothing to lose, set up a new shop.
  # The receiving wallet in this volume stays and is used again.
  log "the database is empty; setting up a new shop"
  rm -f "$INSTALLED" "$CATALOG" "$BUILD" "$VERSION"
fi

if [ ! -f "$INSTALLED" ]; then
  if db_has_shop && [ ! -f "$INSTALLING" ]; then
    # A shop in the database, but this stack's volume does not know it (e.g. the volume was removed).
    # Setting up now would wipe that shop and its orders; refuse instead.
    log "the database already holds a shop, but this stack's state volume does not know it."
    log "reset the whole stack with: docker compose down -v"
    exit 1
  fi
  # Fresh, or an earlier first start that did not finish (nothing in the database belongs to a
  # usable shop yet, so loading the dump over it is safe).
  touch "$INSTALLING"
  log "setting up PrestaShop $(cat "$SETUP/prestashop-version") (well under a minute)"
  sed "s~localhost:80~${LD_DOMAIN}~g" /dump.sql | sql
  console prestashop:module install ledgerdirect --no-interaction
  php "$SETUP/install.php" shop
  cp "$SETUP/prestashop-version" "$VERSION"
  cp "$SETUP/build-id" "$BUILD"
  touch "$INSTALLED"
  rm -f "$INSTALLING"
elif ! cmp -s "$SETUP/prestashop-version" "$VERSION"; then
  # A newer PrestaShop on an older database needs PrestaShop's own upgrade, which this image does
  # not run. Never touch the existing shop silently.
  log "this image brings PrestaShop $(cat "$SETUP/prestashop-version"), the shop was set up with $(cat "$VERSION")."
  log "reset the whole stack with: docker compose down -v"
  exit 1
elif ! cmp -s "$SETUP/build-id" "$BUILD"; then
  log "the module changed since the last start: upgrading it"
  console prestashop:module upgrade ledgerdirect --no-interaction
  cp "$SETUP/build-id" "$BUILD"
fi

php "$SETUP/install.php" config

if ! cmp -s "$SETUP/catalog-id" "$CATALOG"; then
  php "$SETUP/install.php" catalog
  cp "$SETUP/catalog-id" "$CATALOG"
fi
rm -rf var/cache/prod/smarty
log "ready in $(( $(date +%s) - started )) s"

php-fpm -D

# The LedgerDirect cron: settles orders whose payment arrived while no payment page was open.
( while sleep 60; do
    curl -fs -o /dev/null -H "Host: ${LD_DOMAIN}" "http://127.0.0.1$(cat "$STATE/cron-path")" || true
  done ) &

log "shop:  http://${LD_DOMAIN}/"
log "admin: http://${LD_DOMAIN}/admin-dev/  (demo login ${LD_ADMIN_EMAIL} / ${LD_ADMIN_PASSWORD} — local demo only)"
log "receiving account: $(cat "$STATE/account" 2>/dev/null || echo unknown)"
exec nginx -g 'daemon off;'
