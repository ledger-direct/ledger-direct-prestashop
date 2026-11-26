# Changelog

## Unreleased

- The payment page uses @ledger-direct/payment-ui 0.6.0 instead of 0.5.0.
  - **WalletConnect works and has its own button.** With a WalletConnect project id, "Pay with a wallet app
    (WalletConnect)" sits above "Pay with a browser wallet", on every screen width, and opens WalletConnect's
    dialog with the code to scan. Before, the click showed nothing to scan. The wallet app is told the shop's
    name and favicon instead of the host name. The phone's "Open in wallet app" button is Xaman's alone now;
    before, it opened WalletConnect when no Xaman key was set.
  - **Address and destination tag as QR codes of their own,** in a dialog next to their copy buttons, for
    wallets whose scanner fills one field at a time (Joey Wallet reads no payment request).
  - The QR heading reads "Scan with Xaman", and so does the start of the XRPL caption. The caption is centred
    under the code, the "or" between the alternatives is larger, the wallet message is centred.
  - The wallet script is unchanged; the page script and the stylesheet change.

- The payment page uses @ledger-direct/payment-ui 0.5.0 instead of 0.4.2. Joey Wallet's browser
  extension is offered in the wallet list when it is installed; it connects on the page's network,
  pays nothing on another, and a connection Joey ends (access revoked, account gone, network switched)
  is forgotten, so the next click asks Joey again. A wallet error (another network, a wallet that does
  not respond, a failed payment) now shows as a warning box instead of grey text under the hint, where
  it went unseen. The wallet script is unchanged; the page script and the stylesheet change.

## 0.7.2

- The tag 0.7.1 was set on the commit before the release commit, so it carries version 0.7.0;
  tags cannot be moved. 0.7.2 is the same code with its version in place.
- The payment page uses @ledger-direct/payment-ui 0.4.2 instead of 0.4.0, which fixes partial
  payments in the wallet flow: after a partial payment, a wallet button opens the payment for the
  rest instead of reloading the page again and again; an XRP payment whose amount changed while the
  wallet was connecting reloads instead of signing the old amount; a page that turned paid or
  expired meanwhile signs nothing; and an older status answer arriving late no longer turns the page
  back. After payment the page goes to the order confirmation once. The wallet script and the
  stylesheet are unchanged
- The page script and the stylesheet URLs carry the package version, like the wallet library's
  already did, so a browser that cached them before an update loads the new files. Both stay out of
  the theme's "combine, compress and cache" bundles: a bundle keeps its name when the files in it
  change, and a module upgrade does not clear it, so with CCC on the page kept the old package until
  the cache was cleared by hand

## 0.7.0

- Needs `hardcastle/ledger-direct-core` 0.12.0. The checkout offers only what the receiving account
  can take, checked with the quoted amount. A token the account cannot receive (no trust line,
  frozen, full) is not offered, and neither is XRP or XAH for an account that is not activated when
  the order is below the base reserve. So no order exists that nobody can pay
- The settings screen shows, per chain with a saved receiving account, what stands in a customer's
  way: a missing trust line per token, an account that is not activated, deposit authorization, an
  unreachable ledger. The settings stay as they are
- Meets the PrestaShop Addons technical standards:
  - The module declares PrestaShop 9.0.0 up to 9.99.99. Before, it named 8.0.0 as the lowest
    version, which it cannot run on, and the running shop's own version as the highest
  - Every PHP file exits when it is loaded outside PrestaShop
  - An `.htaccess` in the module directory denies direct requests to its PHP files
  - The payment page escapes the asset code where it prints it, not only where it assigns it
- The release archive job checks these rules, so a change that breaks one fails in CI
- Every text a customer or merchant reads is in six languages: English, German, French, Dutch,
  Spanish and European Portuguese. Dutch and Portuguese are new; French and Spanish had only part of
  the payment page and none of the order panel, and English and German lacked the order panel's
  labels and the receiving-account checks. The terms follow the LedgerDirect glossary (German:
  "Herausgeber" for issuer, "Destination Tag", "Testnet-Modus", "Kasse" for checkout)
- The two order states get their names in all six languages on install, not only in German
- A unit test checks every text in the code against every catalogue: present, not empty, the same
  placeholders and tags, and not left in English
- The order panel names a native amount with the order's chain: XAH for a Xahau order. It printed
  XRP for every chain
- `config.xml` names Xahau in the module description, as the module itself has since 0.6.0

## 0.6.3

- Needs `hardcastle/ledger-direct-core` 0.11.3. The payment request in the QR code and behind the
  Xaman button now names its network on the XRP Ledger too, as it already did on Xahau. Before,
  Xaman paid on whichever network the wallet had open, so an XRP payment scanned with a Xahau
  account opened a XAH payment of the same amount
- The payment page uses @ledger-direct/payment-ui 0.4.0 instead of 0.3.1: the wallet library is
  xrpl-connect 1.0.0 with xrpl 5 instead of the release candidate 1.0.0-rc.2. Among its changes, the
  Xaman wallet submits the payment to the ledger under its own control, and wallet availability
  checks time out instead of hanging. The page script and the stylesheet are unchanged
- The URL of the wallet library carries the package version, so a browser that cached it before an
  update fetches the new one

## 0.6.2

- Needs `hardcastle/ledger-direct-core` 0.11.1. When the price oracles disagree, no price is given,
  and no older cached rate stands in for it
- After a testnet reset the stored sync position is ahead of the network. The module now gives the core
  its cache for this, so only the first sync per receiving account sends a failing request; before,
  every sync did
- When no price is available (the oracles failed or disagree), the customer can try again instead of
  reaching a dead end. Requesting a new amount for an expired quote keeps that quote and its button and
  says the price is temporarily unavailable; an order without payment details yet stores nothing and
  offers to try again; the checkout goes back with a message instead of without one. "Please contact
  us" stays for other failures. The page without payment details no longer starts the payment script
- Refreshing an expired quote keeps the payment on record. The new quote and the match of what already
  arrived are now one step under the order's lock. If another run still holds the order after five
  seconds, or the match fails, the page shows the payment on record instead of asking for the full amount

## 0.6.1

- An order is booked as paid exactly once. Overlapping checks of the same order could record the payment
  twice

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
