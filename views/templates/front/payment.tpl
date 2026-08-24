{**
 * Payment instructions for an order awaiting an XRPL transaction.
 *
 * Every value is escaped explicitly rather than relying on the theme's Smarty
 * configuration — the destination account and tag are what the customer's
 * money follows, so their rendering must not depend on a setting a theme can
 * change.
 *}
{extends file='page.tpl'}

{block name='page_title'}
  {l s='Complete your payment' d='Modules.Ledgerdirect.Shop'}
{/block}

{block name='page_content'}
  <section class="ledgerdirect-payment"
           data-ledgerdirect-payment
           {if $ld_intent && !$ld_intent.is_paid}
             data-ld-poll-url="{$ld_poll_url|escape:'html':'UTF-8'}"
             {if $ld_intent.seconds_left !== null}data-ld-seconds-left="{$ld_intent.seconds_left|intval}"{/if}
           {/if}>

    <p>
      {l s='Order' d='Modules.Ledgerdirect.Shop'}
      <strong>{$ld_order_reference|escape:'html':'UTF-8'}</strong>
      &mdash; {$ld_order_total|escape:'html':'UTF-8'}
    </p>

    {if $ld_intent === null}

      <div class="alert alert-danger">
        <p>{l s='We could not load the payment details for this order.' d='Modules.Ledgerdirect.Shop'}</p>
        <p>{l s='Your order was placed. Please contact us so we can send you the payment details.' d='Modules.Ledgerdirect.Shop'}</p>
      </div>

    {else}

      {if $ld_intent.is_testnet}
        <div class="alert alert-warning">
          {l s='Testnet mode: this shop is not accepting real funds.' d='Modules.Ledgerdirect.Shop'}
        </div>
      {/if}

      <div class="alert alert-info">
        <strong>{l s='The destination tag is required.' d='Modules.Ledgerdirect.Shop'}</strong>
        {l s='A payment sent without it cannot be matched to your order.' d='Modules.Ledgerdirect.Shop'}
      </div>

      <div class="row">
        <div class="col-md-5 text-center">
          <img src="{$ld_intent.qr_data_uri|escape:'html':'UTF-8'}"
               alt="{l s='QR code of the destination account' d='Modules.Ledgerdirect.Shop'}"
               width="320" height="320">
          <p class="text-muted small">
            {l s='Scanning fills in the destination account only. Enter the amount and destination tag manually.' d='Modules.Ledgerdirect.Shop'}
          </p>
        </div>

        <div class="col-md-7">
          <dl class="ledgerdirect-details">
            <dt>{l s='Amount' d='Modules.Ledgerdirect.Shop'}</dt>
            <dd>
              <code>{$ld_intent.amount|escape:'html':'UTF-8'}</code>
              {$ld_intent.base_asset|escape:'html':'UTF-8'}
            </dd>

            <dt>{l s='Destination account' d='Modules.Ledgerdirect.Shop'}</dt>
            <dd><code>{$ld_intent.destination_account|escape:'html':'UTF-8'}</code></dd>

            <dt>{l s='Destination tag' d='Modules.Ledgerdirect.Shop'}</dt>
            <dd><code>{$ld_intent.destination_tag|intval}</code></dd>

            {if $ld_intent.issuer}
              <dt>{l s='Issuer' d='Modules.Ledgerdirect.Shop'}</dt>
              <dd><code>{$ld_intent.issuer|escape:'html':'UTF-8'}</code></dd>
            {/if}

            <dt>{l s='Network' d='Modules.Ledgerdirect.Shop'}</dt>
            <dd>{$ld_intent.network|escape:'html':'UTF-8'}</dd>

            <dt>{l s='Exchange rate' d='Modules.Ledgerdirect.Shop'}</dt>
            <dd>{$ld_intent.pairing|escape:'html':'UTF-8'} {$ld_intent.exchange_rate|escape:'html':'UTF-8'}</dd>
          </dl>

          {if $ld_intent.expiry}
            {* Both blocks are always rendered so the countdown can swap them
               without a reload; the server decides which one starts visible. *}
            <p data-ld-live class="text-muted"{if $ld_intent.is_expired} hidden{/if}>
              {l s='This amount is guaranteed for' d='Modules.Ledgerdirect.Shop'}
              <strong data-ld-countdown>{$ld_intent.seconds_left|intval}</strong>
            </p>

            <div data-ld-expired class="alert alert-warning"{if !$ld_intent.is_expired} hidden{/if}>
              <p>{l s='This quote has expired. The exchange rate may have moved since.' d='Modules.Ledgerdirect.Shop'}</p>
              <p>{l s='Already sent the old amount? Do not send it again — use the check button below instead.' d='Modules.Ledgerdirect.Shop'}</p>
              <form method="post" action="{$ld_self_url|escape:'html':'UTF-8'}">
                <button type="submit" name="ld_refresh" value="1" class="btn btn-primary">
                  {l s='Get an updated amount' d='Modules.Ledgerdirect.Shop'}
                </button>
              </form>
            </div>
          {/if}
        </div>
      </div>

      {if $ld_checked_no_payment}
        <div class="alert alert-warning">
          <p>{l s='We could not find your payment on the ledger yet.' d='Modules.Ledgerdirect.Shop'}</p>
          <p>{l s='A transaction usually takes a few seconds to confirm. If you have just sent it, wait a moment and check again.' d='Modules.Ledgerdirect.Shop'}</p>
        </div>
      {/if}

      {* The manual path, and the only one a browser without JavaScript has:
         the countdown and the automatic confirmation both need scripting. *}
      <form method="post" action="{$ld_self_url|escape:'html':'UTF-8'}" class="ledgerdirect-check">
        <button type="submit" name="ld_check" value="1" class="btn btn-primary">
          {l s='I have sent the payment — check now' d='Modules.Ledgerdirect.Shop'}
        </button>
      </form>

      <p class="text-muted">
        {l s='You do not have to wait here: once your transaction is confirmed on the ledger, your order is updated automatically.' d='Modules.Ledgerdirect.Shop'}
      </p>

    {/if}

    <a class="btn btn-secondary" href="{$ld_history_url|escape:'html':'UTF-8'}">
      {l s='Back to your orders' d='Modules.Ledgerdirect.Shop'}
    </a>

  </section>
{/block}
