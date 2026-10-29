# Changelog

## Unreleased

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
