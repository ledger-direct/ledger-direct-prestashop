# LedgerDirect for PrestaShop

Accept XRP, RLUSD and USDC directly on the XRP Ledger — no payment processor, no custody, funds
land in the merchant's own wallet.

The module is the PrestaShop 9 adapter over
[`hardcastle/ledger-direct-core`](https://packagist.org/packages/hardcastle/ledger-direct-core), the
shared package that holds the XRPL and pricing logic. Everything platform-specific — persistence,
checkout, order states, the settings screen — lives here; price conversion, the oracle set, the
stablecoin registry and ledger sync live in the core and are not reimplemented.

## How it works

A customer picks XRP, RLUSD or USDC at checkout. The order is placed straight away in a
**Awaiting XRPL payment** state and the customer gets a payment page: the exact amount, the shop's
receiving address, and a **destination tag** unique to that order. The tag is what ties an incoming
ledger transaction back to the order, so one wallet address serves every customer.

The quote is fixed for a configurable window (five minutes by default). Reloading the page never
changes the amount; once the quote lapses the customer can ask for an updated one, and the
destination tag stays the same so a payment already in flight is not orphaned.

Payments are confirmed three ways, which is deliberate redundancy:

- the payment page polls while the customer is watching,
- a **check now** button for anyone who would rather not wait (and for browsers without JavaScript),
- a cron endpoint that settles orders for customers who closed the page.

An order is credited only from the ledger's `delivered_amount`, only when it covers the requested
amount, and — for stablecoins — only when the currency and issuer match. Anything else is left
waiting and logged.

## Requirements

- PrestaShop 9
- PHP 8.2 or newer
- An XRP Ledger account. Accepting RLUSD or USDC additionally requires a **trustline** to the
  respective issuer on that account, or payments will fail on the ledger.

## Installation

Install the module from the Back Office (Modules → Module Manager → Upload a module) or drop this
directory into `modules/` as `ledgerdirect`.

Installing creates two tables for ledger data plus one for payment records, and registers the
"Awaiting XRPL payment" order state. Uninstalling deliberately **keeps** the tables: the
destination-tag counter cannot be reconstructed, and reusing tags would match new orders against
old payments.

## Configuration

Modules → LedgerDirect → Configure:

| Setting | |
|---|---|
| Receiving address | The shop's XRPL account. Validated as an XRPL address before it is saved. |
| Network | `testnet` or `mainnet`. The address must belong to the selected one. |
| Payment methods | XRP, RLUSD, USDC. Stablecoins are off by default — they need a trustline first. |
| Quote validity | How long a quoted amount stays fixed, 60–3600 seconds. |

Issuer addresses are shown on that screen but cannot be edited. They are fixed in the core: a wrong
issuer would send customer funds to a dead trustline.

The same screen shows the cron URL, including its token:

```
https://<shop>/module/ledgerdirect/cron?token=<token>
```

Call it every few minutes. Without it, a customer who closes the payment page before their
transaction confirms is only settled the next time someone visits their payment page.

## Development

Development tooling lives in `dev/`, as a Composer project of its own. That separation is
load-bearing: PrestaShop autoloads the module's `vendor/` on every request, so a dev dependency
installed there can shadow the shop's own copy of the same package. `dev/vendor/` is never
autoloaded.

The shop runs from a Docker Compose harness that sits one level above this repository and mounts
this directory into `modules/ledgerdirect`.

```
composer install                # the module's runtime dependencies
composer install -d dev         # test tooling

docker compose up -d            # from the harness directory; :8080, Back Office at /admin-dev
```

```
composer test -d dev            # unit suite, no PrestaShop needed

docker compose exec -u www-data -w /var/www/html/modules/ledgerdirect prestashop \
  php dev/vendor/bin/phpunit -c dev/phpunit.xml.dist --testsuite integration

docker compose exec -u www-data -w /var/www/html/modules/ledgerdirect prestashop \
  php dev/vendor/bin/phpstan analyse -c dev/phpstan.neon.dist
```

The unit suite runs anywhere. The integration suite needs a booted PrestaShop and its database, and
is offline by design — it builds payment records directly rather than calling live price oracles.

## Translations

English, German, French and Spanish, as XLIFF catalogues under
`translations/<locale>/`.

## License

MIT — see [LICENSE.md](LICENSE.md).
