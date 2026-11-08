# Changelog

## 0.6.0

- Xahau: XAH on the [Xahau](https://xahau.network) network, a chain of its own that speaks the XRP
  Ledger's protocol, paid by destination tag. A fieldset *Xahau settings* (network, receiving address
  per network, *Accept XAH*, off by default) under the XRP Ledger's, and the payment option "Pay with
  XAH" in the checkout. The payment request behind the QR code names the Xahau network, so Xaman offers
  to switch if it is set to another one; the browser-wallet buttons stay off on a Xahau page. Xahau has
  its own tables (`ledger_direct_xahau_tx`, `ledger_direct_xahau_destination_tag`, created by the
  upgrade) and shares nothing with the XRP Ledger, also for an account that exists on both. The cron
  syncs the configured account of each chain. Saving a Xahau address that is also the XRP Ledger's shows a
  warning: the same address is a different account on each chain. Needs `hardcastle/ledger-direct-core` 0.11
- The payment options come from the core's chain catalogue: one per chain and asset, and a test that
  turns red when the core adds a chain the module has not decided on. The validation controller takes
  `ld_chain` next to `ld_asset`; a link without it means the XRP Ledger, as before
- `dev/bin/e2e.php` takes `--chain` on `configure`, `create-order`, `sync-marker` and `transactions`,
  and reads the sync marker under the core 0.11 key (chain, network, account digest)
- `e2e.yml` runs the case catalogue on Xahau too, after the XRP Ledger, from a CI treasury of its own
  (`LEDGERDIRECT_CI_XAHAU_TREASURY_SEED` / `_ADDRESS`)
- Try it in five minutes: `dev/try` starts a complete PrestaShop 9.1 shop with the module on the XRP
  Ledger testnet (`docker compose up -d --wait`): English and German, a home page that explains the
  demo and offers three hand-painted miniatures (2–4 EUR; their pictures and texts are not under the
  MIT licence, `dev/try/catalog/NOTICE.md`), a demo article for 1 EUR, the shop's own testnet wallet
  with trust lines for RLUSD and USDC, and a demo Back Office login. `.github/workflows/try.yml`
  builds and smoke-tests it on every pull request, with a browser walk through the guest checkout
  for XRP, RLUSD and USDC
- Payment page package 0.3.1 (`views/js/ledger-direct-payment-ui/`): the wallet bundle is built without
  the AMD branch of its dependencies' UMD wrappers. The fix is for Magento, whose pages have RequireJS;
  PrestaShop pages have none and behaved the same before, so nothing changes here — checked on the dev
  shop: wallet list, QR code and console as before

## 0.5.3

- The module says so when its database upgrade has not run: the configuration page and the
  LedgerDirect panel on an order name both versions and the path *Modules → Module Manager →
  LedgerDirect → Upgrade*, and a failed sync logs that the upgrade is pending. PrestaShop does not
  run the upgrade step by itself when newer files arrive; until it has, payments may not be
  confirmed. Found in the first lab run, where the 0.5.2 files sat on a 0.5.1 database (#40, #41)
- `config.xml` ships with the module, in step with its version, so the upgrade scripts run right
  after the zip is uploaded instead of after a second visit to the Module Manager (#39)
- Payment page package 0.3.0 (`views/js/ledger-direct-payment-ui/`): the poll can carry a
  platform's own headers; nothing changes for this module's page (#3 of the package)
- Guzzle 7.16.1

## 0.5.2

- Core 0.9: the synced transactions store the CTID in the column `tx_ctid` instead of `ctid`, the
  name every LedgerDirect plugin uses from now on. The upgrade script renames the column and keeps
  the data (`upgrade/upgrade-0.5.2.php`)
- CI pulls its images from Docker Hub logged in; the nightly end-to-end run has a cap per payment
  and a budget per run

Earlier versions: see the merged pull requests on GitHub.
