{**
 * LedgerDirect block on the Back Office order page (hook displayAdminOrderSide).
 * Read-only: state, amounts, and every transaction on the destination tag.
 *}
<div class="card mt-2" id="ledgerdirect-order-panel">
  <div class="card-header">
    <h3 class="card-header-title">
      LedgerDirect &mdash; {l s='XRPL payment' d='Modules.Ledgerdirect.Admin'}
    </h3>
  </div>
  <div class="card-body">
    <dl class="row mb-0">
      <dt class="col-5">{l s='State' d='Modules.Ledgerdirect.Admin'}</dt>
      <dd class="col-7"><strong data-ld-admin-state="{$ld_panel.state|escape:'html':'UTF-8'}">{$ld_state_label|escape:'html':'UTF-8'}</strong></dd>

      <dt class="col-5">{l s='Requested' d='Modules.Ledgerdirect.Admin'}</dt>
      <dd class="col-7">{$ld_panel.amount|escape:'html':'UTF-8'} {$ld_panel.base_asset|escape:'html':'UTF-8'}</dd>

      {if $ld_panel.amount_paid !== null}
        <dt class="col-5">{l s='Received' d='Modules.Ledgerdirect.Admin'}</dt>
        <dd class="col-7">
          {$ld_panel.amount_paid|escape:'html':'UTF-8'}
          {if $ld_panel.state === 'wrong_asset'}
            <span class="text-muted">({l s='another currency or issuer' d='Modules.Ledgerdirect.Admin'})</span>
          {else}
            {$ld_panel.base_asset|escape:'html':'UTF-8'}
          {/if}
        </dd>
      {/if}

      {if $ld_panel.shortfall !== null}
        <dt class="col-5">{l s='Outstanding' d='Modules.Ledgerdirect.Admin'}</dt>
        <dd class="col-7">{$ld_panel.shortfall|escape:'html':'UTF-8'} {$ld_panel.base_asset|escape:'html':'UTF-8'}</dd>
      {/if}

      <dt class="col-5">{l s='Destination tag' d='Modules.Ledgerdirect.Admin'}</dt>
      <dd class="col-7"><code>{$ld_panel.destination_tag|intval}</code></dd>

      <dt class="col-5">{l s='Network' d='Modules.Ledgerdirect.Admin'}</dt>
      <dd class="col-7">{$ld_panel.network|escape:'html':'UTF-8'}</dd>
    </dl>

    <h4 class="mt-3 mb-2">{l s='Transactions on this destination tag' d='Modules.Ledgerdirect.Admin'}</h4>
    {if $ld_transactions|count === 0}
      <p class="text-muted mb-0">{l s='None yet.' d='Modules.Ledgerdirect.Admin'}</p>
    {else}
      <ul class="list-unstyled mb-0">
        {foreach from=$ld_transactions item=tx}
          <li class="mb-1">
            {if $ld_explorer}
              <a href="{$ld_explorer|escape:'html':'UTF-8'}{$tx.hash|escape:'html':'UTF-8'}" target="_blank" rel="noopener"><code>{$tx.hash|truncate:16:'…'|escape:'html':'UTF-8'}</code></a>
            {else}
              <code>{$tx.hash|truncate:16:'…'|escape:'html':'UTF-8'}</code>
            {/if}
            &mdash;
            {if $tx.delivered === null}
              <span class="text-muted">{l s='no delivered amount' d='Modules.Ledgerdirect.Admin'}</span>
            {else}
              {$tx.delivered|escape:'html':'UTF-8'}{if !$tx.is_issued} XRP{/if}
            {/if}
            <span class="text-muted">{$tx.date|escape:'html':'UTF-8'}</span>
          </li>
        {/foreach}
      </ul>
    {/if}
  </div>
</div>
