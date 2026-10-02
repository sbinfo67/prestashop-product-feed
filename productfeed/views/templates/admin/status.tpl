{**
 * Product feeds for PrestaShop: Google, Microsoft, Meta, Pinterest, TikTok.
 *
 * @author    SBINFO <contact@sbinfo.pro>
 * @copyright 2026 SBINFO
 * @license   MIT https://opensource.org/licenses/MIT
 *}
<div class="panel">
    <div class="panel-heading">
        <i class="icon-rss"></i> {l s='Feed addresses' mod='productfeed'}
    </div>

    <p>
        {l s='Each platform has its own address, in the format it expects. Paste it where the platform asks for the URL of a product file, and leave any user name and password empty: the address itself is the key.' mod='productfeed'}
    </p>

    <table class="table">
        <tbody>
        {foreach $pf_channels as $channel}
            <tr>
                <td style="width: 22%; vertical-align: top; padding-top: 14px;">
                    <strong>{$channel.name|escape:'html':'UTF-8'}</strong>
                    {if $channel.size}<br><small class="text-muted">{$channel.size|escape:'html':'UTF-8'}</small>{/if}
                </td>
                <td>
                    <div class="input-group">
                        <input type="text" class="form-control" id="pf-url-{$channel.id|escape:'html':'UTF-8'}" value="{$channel.url|escape:'html':'UTF-8'}" readonly onclick="this.select();">
                        <span class="input-group-btn">
                            <button type="button" class="btn btn-default" onclick="var f = document.getElementById('pf-url-{$channel.id|escape:'html':'UTF-8'}'); f.select(); if (navigator.clipboard) { navigator.clipboard.writeText(f.value); } else { document.execCommand('copy'); } this.blur();">
                                <i class="icon-copy"></i> {l s='Copy' mod='productfeed'}
                            </button>
                            <a class="btn btn-default" href="{$channel.url|escape:'html':'UTF-8'}" target="_blank" rel="noopener noreferrer">
                                <i class="icon-external-link"></i> {l s='Open' mod='productfeed'}
                            </a>
                        </span>
                    </div>
                    {* Already escaped by Module::l(). *}
                    <p class="help-block" style="margin-bottom: 0;">{$channel.help}</p>
                </td>
            </tr>
        {/foreach}
        </tbody>
    </table>

    <p class="help-block">
        {l s='Download schedule: daily. Each change to a product from the back office regenerates the files straight away; stock changes caused by orders are applied when a platform downloads its file.' mod='productfeed'}
        {l s='The address of the former Microsoft module keeps answering, in the Microsoft format:' mod='productfeed'}
        <code>{$pf_legacy_url|escape:'html':'UTF-8'}</code>
    </p>

    {if $pf_meta}
        <table class="table" style="margin-top: 15px;">
            <tbody>
            <tr>
                <td style="width: 30%;"><strong>{l s='Last generated' mod='productfeed'}</strong></td>
                <td>
                    {$pf_generated|escape:'html':'UTF-8'}
                    {if $pf_pending}
                        <span class="label label-info">{l s='Changes waiting: the files will be regenerated at the next download' mod='productfeed'}</span>
                    {/if}
                </td>
            </tr>
            <tr>
                <td><strong>{l s='Products sent' mod='productfeed'}</strong></td>
                <td>
                    <strong>{$pf_meta.offers|intval}</strong>
                    {l s='lines, from %d active products' sprintf=[$pf_meta.products|intval] mod='productfeed'}
                </td>
            </tr>
            <tr>
                <td><strong>{l s='Language and currency' mod='productfeed'}</strong></td>
                <td>{$pf_meta.language|escape:'html':'UTF-8'}, {$pf_meta.currency|escape:'html':'UTF-8'}</td>
            </tr>
            </tbody>
        </table>
    {else}
        <div class="alert alert-warning" style="margin-top: 15px;">
            {l s='The feeds have not been generated yet.' mod='productfeed'}
        </div>
    {/if}

    {if $pf_summary}
        <h4 style="margin-top: 20px;">{l s='Points to check' mod='productfeed'}</h4>
        <table class="table">
            <tbody>
            {foreach $pf_summary as $line}
                <tr>
                    <td style="width: 30%;">
                        {if $line.kind == 'skipped'}
                            <span class="label label-danger">{l s='Not sent' mod='productfeed'}</span>
                        {else}
                            <span class="label label-warning">{l s='Sent, to improve' mod='productfeed'}</span>
                        {/if}
                    </td>
                    <td>{$line.label|escape:'html':'UTF-8'}</td>
                    <td style="width: 10%; text-align: right;"><strong>{$line.count|intval}</strong></td>
                </tr>
            {/foreach}
            </tbody>
        </table>

        {if $pf_issues}
            <details style="margin-top: 10px;">
                <summary style="cursor: pointer;">{l s='Show the products concerned' mod='productfeed'}</summary>
                <table class="table" style="margin-top: 10px;">
                    <thead>
                    <tr>
                        <th>{l s='ID' mod='productfeed'}</th>
                        <th>{l s='Product' mod='productfeed'}</th>
                        <th>{l s='Problem' mod='productfeed'}</th>
                    </tr>
                    </thead>
                    <tbody>
                    {foreach $pf_issues as $issue}
                        <tr>
                            <td>
                                {if $issue.edit_url}
                                    <a href="{$issue.edit_url|escape:'html':'UTF-8'}" target="_blank" rel="noopener noreferrer">{$issue.id_product|intval}</a>
                                {else}
                                    {$issue.id_product|intval}
                                {/if}
                            </td>
                            <td>{$issue.label|escape:'html':'UTF-8'}</td>
                            <td>
                                {$issue.reason_label|escape:'html':'UTF-8'}
                                {if $issue.detail}<br><small class="text-muted">{$issue.detail|escape:'html':'UTF-8'}</small>{/if}
                            </td>
                        </tr>
                    {/foreach}
                    </tbody>
                </table>
            </details>
        {/if}
    {/if}

    <form method="post" action="{$pf_form_action|escape:'html':'UTF-8'}" style="margin-top: 20px;">
        <button type="submit" name="submitMsAdsFeedRebuild" class="btn btn-default">
            <i class="icon-refresh"></i> {l s='Regenerate now' mod='productfeed'}
        </button>
        <button type="submit" name="submitMsAdsFeedRenewToken" class="btn btn-default"
                data-confirm="{l s='The current addresses will stop working, the former Microsoft one included. You will have to paste the new ones on every platform. Continue?' mod='productfeed'}"
                onclick="return confirm(this.getAttribute('data-confirm'));">
            <i class="icon-key"></i> {l s='Change the addresses' mod='productfeed'}
        </button>
    </form>
</div>
