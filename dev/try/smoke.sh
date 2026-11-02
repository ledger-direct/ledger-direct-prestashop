#!/usr/bin/env bash
#
# Smoke test of a running try stack (dev/try/compose.yaml), the way a visitor would use it: the
# shop answers in English and German, the demo article and the miniatures are in their categories, a
# guest checkout with XRP (over HTTP, the storefront's own forms) gets a payment page that renders the
# markup contract of @ledger-direct/payment-ui, and the status endpoint answers the payment-status
# contract with `waiting` (case PS-01 of the core's catalogue); a wrong key gets 403 (PS-07).
#
#   dev/try/smoke.sh [base url]          default http://localhost:8089
#
# Needs curl and jq. Exits non-zero on the first failure.
set -euo pipefail
BASE=${1:-http://localhost:${LD_TRY_PORT:-8089}}
BASE=${BASE%/}
catalog="$(dirname "$0")/catalog/catalog.json"

pass() { printf 'ok    %s\n' "$*"; }
fail() { printf 'FAIL  %s\n' "$*" >&2; exit 1; }
jar=$(mktemp)
trap 'rm -f "$jar"' EXIT
# The visitor's session: one cookie jar for the whole checkout.
get() { curl -fsS -b "$jar" -c "$jar" "$@"; }
post() { curl -fsS -b "$jar" -c "$jar" -X POST "$@"; }
html() { sed 's/&/\&amp;/g' <<<"$1"; }

code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/")
[ "$code" = 200 ] || fail "storefront answered $code"
pass "storefront answers"

# Read the page first: `curl | grep -q` under pipefail fails whenever grep exits before curl has
# written everything (SIGPIPE), even though the text is there.
home=$(get "$BASE/")
# Categories are found by their URL key; PrestaShop puts the id in front of it (/12-miniatures).
url_of() { grep -o "href=\"$BASE/[0-9]*-$1\"" <<<"$home" | head -1 | cut -d'"' -f2; }
demo_url=$(url_of ledger-direct-demo)
mini_url=$(url_of "$(jq -r .category.url_key "$catalog")")
[ -n "$demo_url" ] || fail "the main menu lacks the LedgerDirect demo category"
[ -n "$mini_url" ] || fail "the main menu lacks the Miniatures category"

category=$(get "$demo_url")
grep -q 'LedgerDirect demo article' <<<"$category" || fail "the demo category does not list the demo article"
product=$(grep -o 'data-id-product="[0-9]*"' <<<"$category" | head -1 | cut -d'"' -f2)
[ -n "$product" ] || fail "no product id in the demo category"
pass "demo category lists the demo article (product $product)"

# The miniatures from dev/try/catalog/catalog.json, in their category and on the home page.
miniatures=$(get "$mini_url")
while IFS= read -r name; do
  grep -qF "$(html "$name")" <<<"$miniatures" || fail "the Miniatures category lacks \"$name\""
  grep -qF "$(html "$name")" <<<"$home" || fail "the home page lacks \"$name\""
done < <(jq -r '.products[]."en-GB".name' "$catalog")
pass "home page and the Miniatures category show $(jq '.products | length' "$catalog") miniatures"

german=$(curl -fsS "$BASE/de/")
grep -qF "$(jq -r '.category."de-DE".name' "$catalog")" <<<"$german" || fail "the German home page lacks the category name"
grep -q 'Bezahlen mit LedgerDirect' <<<"$german" || fail "the German home page lacks the explanation"
pass "German storefront at /de/"

# Guest checkout, the forms the storefront posts: cart, personal information, address, delivery,
# then the LedgerDirect payment option for XRP.
token=$(grep -o '"static_token":"[0-9a-f]*"' <<<"$home" | head -1 | cut -d'"' -f4)
[ -n "$token" ] || fail "no static token on the home page"
added=$(post "$BASE/cart" -d "token=$token&id_product=$product&id_customization=0&qty=1&add=1&action=update&ajax=1")
[ "$(jq -r .success <<<"$added")" = true ] || fail "adding to the cart failed: $added"
post "$BASE/order" -o /dev/null -d 'id_gender=1&firstname=Try&lastname=Visitor&email=visitor@example.invalid&psgdpr=1&customer_privacy=1&submitCreate=1&continue=1'
step=$(get "$BASE/order")
token=$(grep -o 'name="token" value="[0-9a-f]*"' <<<"$step" | head -1 | cut -d'"' -f4)
[ -n "$token" ] || fail "the checkout shows no address form"
post "$BASE/order" -o /dev/null -d "token=$token" -d 'firstname=Try&lastname=Visitor&address1=1+Testnet+Way&postcode=10115&city=Berlin&id_country=1&phone=0301234567&saveAddress=delivery&use_same_address=1&confirm-addresses=1'
step=$(get "$BASE/order")
address=$(grep -o 'name="delivery_option\[[0-9]*\]"' <<<"$step" | head -1 | grep -o '[0-9]*')
[ -n "$address" ] || fail "the checkout offers no delivery option"
post "$BASE/order" -o /dev/null --data-urlencode "delivery_option[$address]=2," -d 'confirmDeliveryOption=1'
page_url=$(curl -s -b "$jar" -c "$jar" -o /dev/null -w '%{redirect_url}' "$BASE/module/ledgerdirect/validation?ld_asset=XRP")
[[ "$page_url" == *"/module/ledgerdirect/payment"* ]] || fail "placing the order did not lead to the payment page: $page_url"
order=$(sed -n 's/.*[?&]id_order=\([0-9]*\).*/\1/p' <<<"$page_url")
key=$(sed -n 's/.*[?&]key=\([0-9a-f]*\).*/\1/p' <<<"$page_url")
pass "guest order $order placed with XRP"

page=$(mktemp)
code=$(curl -s -o "$page" -w '%{http_code}' "$BASE/module/ledgerdirect/payment?id_order=$order&key=$key")
[ "$code" = 200 ] || fail "payment page answered $code"
for attribute in 'data-ld-page' 'data-ld-state="waiting"' 'data-ld-amount-requested="' 'data-ld-asset="XRP"' 'data-ld-poll-url="' 'data-ld-account' 'data-ld-tag'; do
  grep -q -- "$attribute" "$page" || fail "payment page lacks $attribute"
done
amount=$(grep -o 'data-ld-amount-requested="[^"]*"' "$page" | head -1 | cut -d'"' -f2)
rm -f "$page"
pass "payment page renders the markup contract (amount $amount XRP)"

status=$(curl -fsS -H 'Accept: application/json' "$BASE/module/ledgerdirect/poll?id_order=$order&key=$key")
[ "$(jq -r .state <<<"$status")" = waiting ] || fail "status endpoint: $status"
[ "$(jq -r '.seconds_left > 0' <<<"$status")" = true ] || fail "status endpoint without seconds_left: $status"
pass "status endpoint: waiting, $(jq -r .seconds_left <<<"$status") s left (PS-01)"

code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/module/ledgerdirect/poll?id_order=$order&key=wrong")
[ "$code" = 403 ] || fail "a wrong key got $code instead of 403"
pass "a wrong key is refused with 403 (PS-07)"

printf '\npayment page: %s\n' "$BASE/module/ledgerdirect/payment?id_order=$order&key=$key"
