{**
 * The LedgerDirect payment page.
 *
 * Every sentence a customer reads is here and in the XLIFF catalogues,
 * nowhere else; one block per payment state (waiting, partial, wrong_asset,
 * expired), all rendered, the server decides which starts visible. The
 * script — @ledger-direct/payment-ui, copied into views/ — only switches
 * blocks, inserts numbers and polls. A settled order never renders this page;
 * the controller redirects it.
 *
 * Markup contract: the package's src/README.md (data-ld-* attributes). No
 * arithmetic on amounts here: every amount comes out of the presenter as the
 * core's plain decimal. Every value is escaped explicitly rather than relying
 * on the theme's Smarty configuration — the destination account and tag are
 * what the customer's money follows.
 *
 * The page stands on its own: the module's overrideLayoutTemplate hook hands
 * PrestaShop the theme's content-only layout, so extending $layout here gives
 * the theme's <head> and stylesheets and nothing else. Should a theme lack
 * that layout, the page renders inside the theme's frame — still the same
 * page.
 *}
{extends file=$layout}

{block name='notifications'}{/block}
{block name='breadcrumb'}{/block}
{block name='footer'}{/block}

{block name='content'}
{capture assign='copyIcon'}<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>{/capture}
{capture assign='infoIcon'}<svg class="ld-notice-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/></svg>{/capture}
{capture assign='copyLabels'}<span data-ld-copy-label="idle">{l s='Copy' d='Modules.Ledgerdirect.Shop'}</span><span data-ld-copy-label="done" hidden>{l s='Copied' d='Modules.Ledgerdirect.Shop'}</span>{/capture}

{if $ld_intent === null}

  <div class="ld-page" data-ld-page style="--ld-accent: {$ld_accent|escape:'html':'UTF-8'}">
    <main class="ld-main">
      <div class="ld-card">
        <div class="ld-notice ld-notice--warn" role="status">
          {$infoIcon nofilter}
          <div>
            <p><strong>{l s='We could not load the payment details for this order.' d='Modules.Ledgerdirect.Shop'}</strong></p>
            <p>{l s='Your order was placed. Please contact us so we can send you the payment details.' d='Modules.Ledgerdirect.Shop'}</p>
            <p>{l s='Order' d='Modules.Ledgerdirect.Shop'} <strong>{$ld_order_reference|escape:'html':'UTF-8'}</strong></p>
          </div>
        </div>
        <a class="ld-btn ld-btn--secondary" href="{$ld_history_url|escape:'html':'UTF-8'}">{l s='Back to your orders' d='Modules.Ledgerdirect.Shop'}</a>
      </div>
    </main>
  </div>

{else}

  {assign var='state' value=$ld_intent.state}
  {assign var='asset' value=$ld_intent.base_asset|escape:'html':'UTF-8'}
  {assign var='hasWalletApp' value=($ld_xaman_key !== '' || $ld_wc_project !== '')}

  <div class="ld-page"
       data-ld-page
       data-ld-state="{$state|escape:'html':'UTF-8'}"
       data-ld-poll-url="{$ld_poll_url|escape:'html':'UTF-8'}"
       data-ld-asset="{$asset}"
       data-ld-network="{$ld_intent.network|escape:'html':'UTF-8'}"
       data-ld-explorer-base="{$ld_intent.explorer_base|escape:'html':'UTF-8'}"
       data-ld-quote-seconds="{$ld_quote_seconds|intval}"
       data-ld-amount-requested="{$ld_intent.amount|escape:'html':'UTF-8'}"
       data-ld-payment-uri="{$ld_intent.payment_uri|escape:'html':'UTF-8'}"
       data-ld-wallets-src="{$ld_wallets_src|escape:'html':'UTF-8'}"
       {if $ld_intent.seconds_left !== null}data-ld-seconds-left="{$ld_intent.seconds_left|intval}"{/if}
       {if $ld_intent.amount_drops !== null}data-ld-amount-drops="{$ld_intent.amount_drops|escape:'html':'UTF-8'}"{/if}
       {if $ld_intent.currency !== null}data-ld-currency="{$ld_intent.currency|escape:'html':'UTF-8'}" data-ld-issuer="{$ld_intent.issuer|escape:'html':'UTF-8'}"{/if}
       {if $ld_xaman_key !== ''}data-ld-xaman-key="{$ld_xaman_key|escape:'html':'UTF-8'}"{/if}
       {if $ld_wc_project !== ''}data-ld-wc-project="{$ld_wc_project|escape:'html':'UTF-8'}"{/if}
       style="--ld-accent: {$ld_accent|escape:'html':'UTF-8'}">

    <header class="ld-top">
      <a class="ld-shop" href="{$ld_home_url|escape:'html':'UTF-8'}">
        {* The logo, always an <img> with a fixed maximum size; the monogram when there is none. *}
        {if $ld_logo.mode === 'shop' && $shop.logo}
          <span class="ld-shop-logo ld-shop-logo--image"><img src="{$shop.logo|escape:'html':'UTF-8'}" alt="{$ld_shop_name|escape:'html':'UTF-8'}" width="160" height="32"></span>
        {elseif $ld_logo.mode === 'custom' && $ld_logo.url}
          <span class="ld-shop-logo ld-shop-logo--image"><img src="{$ld_logo.url|escape:'html':'UTF-8'}" alt="{$ld_shop_name|escape:'html':'UTF-8'}" width="160" height="32"></span>
        {else}
          <span class="ld-shop-logo" aria-hidden="true">{$ld_logo.monogram|escape:'html':'UTF-8'}</span>
        {/if}
        <span>{$ld_shop_name|escape:'html':'UTF-8'}</span>
      </a>
      <div class="ld-top-meta">
        {if $ld_intent.is_testnet}
          <span class="ld-badge ld-badge--testnet">{l s='Testnet – no real money' d='Modules.Ledgerdirect.Shop'}</span><br>
        {/if}
        {l s='Order' d='Modules.Ledgerdirect.Shop'} <strong>{$ld_order_reference|escape:'html':'UTF-8'}</strong>
      </div>
    </header>

    <main class="ld-main">
      <div class="ld-card">

        <div data-ld-open>
          <div class="ld-grid">
            <section class="ld-col" aria-labelledby="ld-heading">
              <h1 id="ld-heading" class="ld-sr">{l s='Complete your payment' d='Modules.Ledgerdirect.Shop'}</h1>

              {* Something arrived but the order is not paid. Neutral tone on purpose. *}
              <div class="ld-notice ld-notice--info" data-ld-block="partial" role="status"{if $state !== 'partial'} hidden{/if}>
                {$infoIcon nofilter}
                <div>
                  <p>{l s='%paid% have arrived – thank you. %shortfall% are still missing.'
                        sprintf=[
                          '%paid%' => "<strong><span data-ld-paid>{$ld_intent.amount_paid|escape:'html':'UTF-8'}</span> {$asset}</strong>",
                          '%shortfall%' => "<strong><span data-ld-shortfall>{$ld_intent.shortfall|escape:'html':'UTF-8'}</span> {$asset}</strong>"
                        ]
                        d='Modules.Ledgerdirect.Shop'}</p>
                  <div class="ld-progress" aria-hidden="true"><span data-ld-progress style="width: {$ld_intent.paid_share|intval}%"></span></div>
                  <p>{l s='Please send the rest to the same address with the same destination tag.' d='Modules.Ledgerdirect.Shop'}</p>
                </div>
              </div>

              <div class="ld-notice ld-notice--info" data-ld-block="wrong_asset" role="status"{if $state !== 'wrong_asset'} hidden{/if}>
                {$infoIcon nofilter}
                <div>
                  <p>{l s='A payment of %paid% has arrived, but this order expects %asset% from the issuer named below. It cannot be credited.'
                        sprintf=[
                          '%paid%' => "<strong data-ld-paid>{$ld_intent.amount_paid|escape:'html':'UTF-8'}</strong>",
                          '%asset%' => "<strong>{$asset}</strong>"
                        ]
                        d='Modules.Ledgerdirect.Shop'}</p>
                  <p>{l s='Please send %shortfall% – or contact us about the payment you already made.'
                        sprintf=['%shortfall%' => "<strong><span data-ld-shortfall>{$ld_intent.shortfall|escape:'html':'UTF-8'}</span> {$asset}</strong>"]
                        d='Modules.Ledgerdirect.Shop'}</p>
                </div>
              </div>

              <div class="ld-notice ld-notice--warn" data-ld-block="expired" role="status"{if $state !== 'expired'} hidden{/if}>
                <svg class="ld-notice-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 9v4M12 17h.01"/><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/></svg>
                <div>
                  <p><strong>{l s='This amount is no longer valid.' d='Modules.Ledgerdirect.Shop'}</strong> {l s='The exchange rate may have moved since.' d='Modules.Ledgerdirect.Shop'}</p>
                  <p>{l s='Already sent? Then do not send again – we will still recognise the payment.' d='Modules.Ledgerdirect.Shop'}</p>
                  <form method="post" action="{$ld_self_url|escape:'html':'UTF-8'}">
                    <button type="submit" name="ld_refresh" value="1" class="ld-btn ld-btn--primary">{l s='Request a new amount' d='Modules.Ledgerdirect.Shop'}</button>
                  </form>
                </div>
              </div>

              <p class="ld-eyebrow" data-ld-amount-label>
                <span data-ld-label="due"{if $state === 'partial'} hidden{/if}>{l s='Amount to send' d='Modules.Ledgerdirect.Shop'}</span>
                <span data-ld-label="remaining"{if $state !== 'partial'} hidden{/if}>{l s='Still to send' d='Modules.Ledgerdirect.Shop'}</span>
              </p>
              <div class="ld-amount">
                <span class="ld-amount-value" data-ld-amount>{$ld_intent.amount_due|escape:'html':'UTF-8'}</span>
                <span class="ld-amount-asset">{$asset}</span>
                <button type="button" class="ld-copy" data-copy="amount" aria-label="{l s='Copy amount' d='Modules.Ledgerdirect.Shop'}">{$copyIcon nofilter}{$copyLabels nofilter}</button>
              </div>
              <p class="ld-fiat" data-ld-fiat>
                <span data-ld-fiat-for="full"{if $state === 'partial'} hidden{/if}>{l s='equals %fiat%' sprintf=['%fiat%' => $ld_order_total|escape:'html':'UTF-8'] d='Modules.Ledgerdirect.Shop'}</span>
                <span data-ld-fiat-for="partial"{if $state !== 'partial'} hidden{/if}>{l s='of %total% %asset% in total (%fiat%)' sprintf=['%total%' => $ld_intent.amount|escape:'html':'UTF-8', '%asset%' => $asset, '%fiat%' => $ld_order_total|escape:'html':'UTF-8'] d='Modules.Ledgerdirect.Shop'}</span>
              </p>

              {if $ld_intent.expiry}
                <div class="ld-timer" data-ld-timer data-ld-block="waiting" aria-live="off"{if $state !== 'waiting'} hidden{/if}>
                  {l s='Amount guaranteed for %countdown% minutes' sprintf=['%countdown%' => "<strong data-ld-countdown>{$ld_intent.seconds_left|intval}</strong>"] d='Modules.Ledgerdirect.Shop'}
                  <div class="ld-timer-bar" aria-hidden="true"><span data-ld-timer-bar style="width: 100%"></span></div>
                </div>
              {/if}

              <ol class="ld-steps">
                <li>
                  <div class="ld-field-label"><span class="ld-step-no">1</span> {l s='Receiving address' d='Modules.Ledgerdirect.Shop'}</div>
                  <div class="ld-field">
                    <span class="ld-field-value" data-ld-account data-value="{$ld_intent.destination_account|escape:'html':'UTF-8'}">{$ld_intent.destination_account|escape:'html':'UTF-8'}</span>
                    <button type="button" class="ld-copy" data-copy="account" aria-label="{l s='Copy address' d='Modules.Ledgerdirect.Shop'}">{$copyIcon nofilter}{$copyLabels nofilter}</button>
                  </div>
                </li>
                <li>
                  <div class="ld-field-label"><span class="ld-step-no">2</span> {l s='Destination tag' d='Modules.Ledgerdirect.Shop'} <span class="ld-badge ld-badge--required">{l s='Required' d='Modules.Ledgerdirect.Shop'}</span></div>
                  <div class="ld-field ld-field--key">
                    <span class="ld-field-value" data-ld-tag data-value="{$ld_intent.destination_tag|intval}">{$ld_intent.destination_tag|intval}</span>
                    <button type="button" class="ld-copy" data-copy="tag" aria-label="{l s='Copy destination tag' d='Modules.Ledgerdirect.Shop'}">{$copyIcon nofilter}{$copyLabels nofilter}</button>
                  </div>
                  <p class="ld-hint">{l s='Without this tag we cannot match the payment to your order.' d='Modules.Ledgerdirect.Shop'}</p>
                </li>
                {if $ld_intent.issuer}
                  <li>
                    <div class="ld-field-label"><span class="ld-step-no">3</span> {l s='Token and issuer' d='Modules.Ledgerdirect.Shop'}</div>
                    <div class="ld-field">
                      <span class="ld-field-value">{$asset} · <span data-ld-issuer data-value="{$ld_intent.issuer|escape:'html':'UTF-8'}">{$ld_intent.issuer|escape:'html':'UTF-8'}</span></span>
                      <button type="button" class="ld-copy" data-copy="issuer" aria-label="{l s='Copy issuer' d='Modules.Ledgerdirect.Shop'}">{$copyIcon nofilter}{$copyLabels nofilter}</button>
                    </div>
                    <p class="ld-hint">{l s='Only %asset% from exactly this issuer is credited. Your wallet needs a trust line for it.' sprintf=['%asset%' => $asset] d='Modules.Ledgerdirect.Shop'}</p>
                  </li>
                {/if}
              </ol>

              <details class="ld-exchange">
                <summary>{l s='Paying from an exchange?' d='Modules.Ledgerdirect.Shop'}</summary>
                <p>{l s="The destination tag is required, and the amount must still arrive in full after the exchange's fee." d='Modules.Ledgerdirect.Shop'}</p>
              </details>
            </section>

            <aside class="ld-col ld-col--side" aria-label="{l s='Pay with a wallet' d='Modules.Ledgerdirect.Shop'}">
              <details class="ld-qr" data-ld-qr-details open>
                <summary><span class="ld-qr-sum-open">{l s='Scan with a wallet app' d='Modules.Ledgerdirect.Shop'}</span><span class="ld-qr-sum-closed">{l s='Show QR code ▾' d='Modules.Ledgerdirect.Shop'}</span></summary>
                <div class="ld-qr-box{if $state === 'expired'} is-void{/if}" data-ld-qr-box>
                  {* Server-rendered code of the same payment request, for a browser without scripts; the script redraws it. *}
                  <div data-ld-qr data-ld-qr-label="{l s='QR code with address, destination tag and amount' d='Modules.Ledgerdirect.Shop'}"><img src="{$ld_intent.qr_data_uri|escape:'html':'UTF-8'}" alt="{l s='QR code with address, destination tag and amount' d='Modules.Ledgerdirect.Shop'}" width="220" height="220"></div>
                  <div class="ld-qr-void" data-ld-qr-void{if $state !== 'expired'} hidden{/if}>{l s='Expired – please request a new amount' d='Modules.Ledgerdirect.Shop'}</div>
                </div>
                <p class="ld-qr-caption">{l s='Scan with Xaman or another XRPL wallet. Address, destination tag and amount are filled in – please check them before sending.' d='Modules.Ledgerdirect.Shop'}</p>
              </details>

              {* Browser wallets, loaded only on click. Every sentence the module can show is rendered here. *}
              <div data-ld-wallet-section{if $hasWalletApp} data-ld-wallet-mobile{/if}{if $state === 'expired'} hidden{/if}>
                <div class="ld-or">{l s='or' d='Modules.Ledgerdirect.Shop'}</div>
                <div data-ld-wallet-desktop>
                  <button type="button" class="ld-btn ld-btn--secondary ld-btn--block" data-ld-wallet-toggle aria-expanded="false" aria-controls="ld-wallets">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="2" y="6" width="20" height="14" rx="2"/><path d="M16 13h2M2 10h20"/></svg>
                    {l s='Pay with a browser wallet' d='Modules.Ledgerdirect.Shop'}
                  </button>
                  <div class="ld-wallets" id="ld-wallets" data-ld-wallets hidden>
                    <p class="ld-hint" data-ld-wallet-status="loading" hidden>{l s='Looking for wallets …' d='Modules.Ledgerdirect.Shop'}</p>
                    <div data-ld-wallet-list></div>
                    <p class="ld-hint" data-ld-wallet-status="none" hidden>{l s='No browser wallet found. Use the QR code or copy the details.' d='Modules.Ledgerdirect.Shop'}</p>
                    <p class="ld-hint" data-ld-wallet-status="hint" hidden>{l s='The wallet fills in the payment. You confirm it there.' d='Modules.Ledgerdirect.Shop'}</p>
                  </div>
                </div>
                {if $hasWalletApp}
                  <button type="button" class="ld-btn ld-btn--secondary ld-btn--block" data-ld-wallet-app data-ld-wallet-id="{if $ld_xaman_key !== ''}xaman{else}walletconnect{/if}">{l s='Open in wallet app' d='Modules.Ledgerdirect.Shop'}</button>
                {/if}
                <p class="ld-hint" data-ld-wallet-message aria-live="polite" hidden></p>
                <span hidden data-ld-wallet-text="found">{l s='found' d='Modules.Ledgerdirect.Shop'}</span>
                <span hidden data-ld-wallet-text="confirm">{l s='Confirm in your wallet …' d='Modules.Ledgerdirect.Shop'}</span>
                <span hidden data-ld-wallet-text="submitted">{l s='Sent – we are checking for it.' d='Modules.Ledgerdirect.Shop'}</span>
                <span hidden data-ld-wallet-text="error-unavailable">{l s='The wallet does not respond. Is the extension installed and unlocked?' d='Modules.Ledgerdirect.Shop'}</span>
                <span hidden data-ld-wallet-text="error-network">{l s='The connection to the wallet failed – please try again.' d='Modules.Ledgerdirect.Shop'}</span>
                <span hidden data-ld-wallet-text="error-mismatch">{l s='Your wallet is set to another network. Please switch to %network%.' sprintf=['%network%' => '%network%'] d='Modules.Ledgerdirect.Shop'}</span>
                <span hidden data-ld-wallet-text="error-other">{l s='The payment through the wallet failed. Please use the QR code or copy the details.' d='Modules.Ledgerdirect.Shop'}</span>
                <span hidden data-ld-wallet-text="network-mainnet">{l s='XRPL Mainnet' d='Modules.Ledgerdirect.Shop'}</span>
                <span hidden data-ld-wallet-text="network-testnet">{l s='XRPL Testnet' d='Modules.Ledgerdirect.Shop'}</span>
              </div>

              <dl class="ld-details">
                <dt>{l s='Order total' d='Modules.Ledgerdirect.Shop'}</dt><dd>{$ld_order_total|escape:'html':'UTF-8'}</dd>
                <dt>{l s='Rate' d='Modules.Ledgerdirect.Shop'}</dt><dd>{l s='1 %asset% = %rate% %currency%' sprintf=['%asset%' => $asset, '%rate%' => $ld_intent.exchange_rate|escape:'html':'UTF-8', '%currency%' => $ld_intent.quote_currency|escape:'html':'UTF-8'] d='Modules.Ledgerdirect.Shop'}</dd>
                <dt>{l s='Network' d='Modules.Ledgerdirect.Shop'}</dt><dd>{if $ld_intent.is_testnet}{l s='XRPL Testnet' d='Modules.Ledgerdirect.Shop'}{else}{l s='XRPL Mainnet' d='Modules.Ledgerdirect.Shop'}{/if}</dd>
              </dl>
            </aside>
          </div>

          <div class="ld-status">
            <div class="ld-status-text" aria-live="polite">
              <span class="ld-pulse" aria-hidden="true"></span>
              <span data-ld-status-for="waiting"{if $state !== 'waiting'} hidden{/if}>{l s='Waiting for your payment. This page updates by itself.' d='Modules.Ledgerdirect.Shop'}</span>
              <span data-ld-status-for="partial"{if $state !== 'partial'} hidden{/if}>{l s='Partial payment received. Waiting for the rest.' d='Modules.Ledgerdirect.Shop'}</span>
              <span data-ld-status-for="wrong_asset"{if $state !== 'wrong_asset'} hidden{/if}>{l s='Payment in the wrong token received. Waiting for the right amount.' d='Modules.Ledgerdirect.Shop'}</span>
              <span data-ld-status-for="expired"{if $state !== 'expired'} hidden{/if}>{l s='A late payment of the old amount is still recognised.' d='Modules.Ledgerdirect.Shop'}</span>
            </div>
            {* The manual path, and the only one a browser without JavaScript has: a plain post back to this page. *}
            <form method="post" action="{$ld_self_url|escape:'html':'UTF-8'}" data-ld-check-form>
              <button type="submit" name="ld_check" value="1" class="ld-btn ld-btn--secondary" data-ld-check>
                <span data-ld-check-label="idle">{l s='Check payment now' d='Modules.Ledgerdirect.Shop'}</span>
                <span data-ld-check-label="busy" hidden><span class="ld-spinner" aria-hidden="true"></span> {l s='Checking …' d='Modules.Ledgerdirect.Shop'}</span>
              </button>
            </form>
            <p class="ld-toast" data-ld-toast{if !$ld_checked_no_payment} hidden{/if}>{l s='No payment found yet. A transaction usually takes only a few seconds – please check again in a moment.' d='Modules.Ledgerdirect.Shop'}</p>
          </div>
        </div>

        {* Shown by the script when the poll reports settled; a page loaded for a paid order is redirected by the controller. *}
        <div class="ld-success" data-ld-success hidden>
          <div class="ld-success-icon" aria-hidden="true"><svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6 9 17l-5-5"/></svg></div>
          <h2>{l s='Payment received' d='Modules.Ledgerdirect.Shop'}</h2>
          <p>{l s='%amount% have arrived.' sprintf=['%amount%' => "<strong><span data-ld-settled-amount></span> {$asset}</strong>"] d='Modules.Ledgerdirect.Shop'}</p>
          <p data-ld-hash-row hidden>{l s='Transaction' d='Modules.Ledgerdirect.Shop'} <a data-ld-hash href="#" target="_blank" rel="noopener"><code></code></a></p>
          <p>{l s='Continuing to your order in %seconds% s' sprintf=['%seconds%' => '<span data-ld-redirect-count>5</span>'] d='Modules.Ledgerdirect.Shop'}</p>
          <a class="ld-btn ld-btn--primary" data-ld-redirect-link href="{$ld_confirmation_url|escape:'html':'UTF-8'}">{l s='Continue to your order' d='Modules.Ledgerdirect.Shop'}</a>
        </div>

      </div>
    </main>

    <footer class="ld-foot">
      <a href="{$ld_cart_url|escape:'html':'UTF-8'}">{l s='Back to cart' d='Modules.Ledgerdirect.Shop'}</a>
      <span>{l s='Paid directly on the XRP Ledger, with no intermediary.' d='Modules.Ledgerdirect.Shop'}</span>
    </footer>
  </div>

{/if}
{/block}
