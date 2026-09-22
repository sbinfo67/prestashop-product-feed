{**
 * Microsoft Ads product feed for PrestaShop.
 *
 * @author    SBINFO <contact@sbinfo.pro>
 * @copyright 2026 SBINFO
 * @license   MIT https://opensource.org/licenses/MIT
 *}
<div class="panel">
    <div class="panel-heading">
        <i class="icon-rss"></i> {l s='Feed address' mod='microsoftadsfeed'}
    </div>

    <p>
        {l s='In Microsoft Merchant Center, create a feed with the input method "Automatically download file from URL" and paste this address as the source URL. Leave the user name and password empty: the address itself is the key.' mod='microsoftadsfeed'}
    </p>

    <div class="input-group" style="max-width: 900px;">
        <input type="text" class="form-control" id="msads-feed-url" value="{$msads_url|escape:'html':'UTF-8'}" readonly onclick="this.select();">
        <span class="input-group-btn">
            <button type="button" class="btn btn-default" onclick="var f = document.getElementById('msads-feed-url'); f.select(); if (navigator.clipboard) { navigator.clipboard.writeText(f.value); } else { document.execCommand('copy'); } this.blur();">
                <i class="icon-copy"></i> {l s='Copy' mod='microsoftadsfeed'}
            </button>
            <a class="btn btn-default" href="{$msads_url|escape:'html':'UTF-8'}" target="_blank" rel="noopener noreferrer">
                <i class="icon-external-link"></i> {l s='Open' mod='microsoftadsfeed'}
            </a>
        </span>
    </div>

    <p class="help-block">
        {l s='Download schedule: daily. Each change to a product from the back office regenerates the file straight away; stock changes caused by orders are applied when Microsoft downloads the file.' mod='microsoftadsfeed'}
    </p>

    {if $msads_meta}
        <table class="table" style="margin-top: 15px;">
            <tbody>
            <tr>
                <td style="width: 30%;"><strong>{l s='Last generated' mod='microsoftadsfeed'}</strong></td>
                <td>
                    {$msads_generated|escape:'html':'UTF-8'}
                    {if $msads_pending}
                        <span class="label label-info">{l s='Changes waiting: the file will be regenerated at the next download' mod='microsoftadsfeed'}</span>
                    {/if}
                </td>
            </tr>
            <tr>
                <td><strong>{l s='Products sent' mod='microsoftadsfeed'}</strong></td>
                <td>
                    <strong>{$msads_meta.offers|intval}</strong>
                    {l s='lines, from %d active products' sprintf=[$msads_meta.products|intval] mod='microsoftadsfeed'}
                </td>
            </tr>
            <tr>
                <td><strong>{l s='File' mod='microsoftadsfeed'}</strong></td>
                <td>
                    {$msads_size|escape:'html':'UTF-8'},
                    {l s='language' mod='microsoftadsfeed'} {$msads_meta.language|escape:'html':'UTF-8'},
                    {l s='currency' mod='microsoftadsfeed'} {$msads_meta.currency|escape:'html':'UTF-8'}
                </td>
            </tr>
            <tr>
                <td><strong>{l s='Columns' mod='microsoftadsfeed'}</strong></td>
                <td><code>{$msads_columns|escape:'html':'UTF-8'}</code></td>
            </tr>
            </tbody>
        </table>
    {else}
        <div class="alert alert-warning" style="margin-top: 15px;">
            {l s='The feed has not been generated yet.' mod='microsoftadsfeed'}
        </div>
    {/if}

    {if $msads_summary}
        <h4 style="margin-top: 20px;">{l s='Points to check' mod='microsoftadsfeed'}</h4>
        <table class="table">
            <tbody>
            {foreach $msads_summary as $line}
                <tr>
                    <td style="width: 30%;">
                        {if $line.kind == 'skipped'}
                            <span class="label label-danger">{l s='Not sent' mod='microsoftadsfeed'}</span>
                        {else}
                            <span class="label label-warning">{l s='Sent, to improve' mod='microsoftadsfeed'}</span>
                        {/if}
                    </td>
                    <td>{$line.label|escape:'html':'UTF-8'}</td>
                    <td style="width: 10%; text-align: right;"><strong>{$line.count|intval}</strong></td>
                </tr>
            {/foreach}
            </tbody>
        </table>

        {if $msads_issues}
            <details style="margin-top: 10px;">
                <summary style="cursor: pointer;">{l s='Show the products concerned' mod='microsoftadsfeed'}</summary>
                <table class="table" style="margin-top: 10px;">
                    <thead>
                    <tr>
                        <th>{l s='ID' mod='microsoftadsfeed'}</th>
                        <th>{l s='Product' mod='microsoftadsfeed'}</th>
                        <th>{l s='Problem' mod='microsoftadsfeed'}</th>
                    </tr>
                    </thead>
                    <tbody>
                    {foreach $msads_issues as $issue}
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

    <form method="post" action="{$msads_form_action|escape:'html':'UTF-8'}" style="margin-top: 20px;">
        <button type="submit" name="submitMsAdsFeedRebuild" class="btn btn-default">
            <i class="icon-refresh"></i> {l s='Regenerate now' mod='microsoftadsfeed'}
        </button>
        <button type="submit" name="submitMsAdsFeedRenewToken" class="btn btn-default"
                data-confirm="{l s='The current address will stop working. You will have to paste the new one into Microsoft Merchant Center. Continue?' mod='microsoftadsfeed'}"
                onclick="return confirm(this.getAttribute('data-confirm'));">
            <i class="icon-key"></i> {l s='Change the address' mod='microsoftadsfeed'}
        </button>
    </form>
</div>
