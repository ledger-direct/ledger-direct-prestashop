{**
 * Shown under the payment method in the checkout. Indicative amount only —
 * the binding quote is created when the order is placed.
 *}
<div class="ledgerdirect-option">
  <p>
    {l s='Pay approximately' d='Modules.Ledgerdirect.Shop'}
    <strong>{$ld_amount|escape:'html':'UTF-8'} {$ld_asset|escape:'html':'UTF-8'}</strong>
    {l s='on the XRP Ledger.' d='Modules.Ledgerdirect.Shop'}
  </p>
  {if $ld_is_testnet}
    <p class="alert alert-warning">
      {l s='Testnet mode: this shop is not accepting real funds.' d='Modules.Ledgerdirect.Shop'}
    </p>
  {/if}
  <p class="text-muted">
    {l s='The exact amount and a destination tag are shown on the next page.' d='Modules.Ledgerdirect.Shop'}
  </p>
</div>
