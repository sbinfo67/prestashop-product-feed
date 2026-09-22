{**
 * Microsoft Ads product feed for PrestaShop.
 *
 * @author    SBINFO <contact@sbinfo.pro>
 * @copyright 2026 SBINFO
 * @license   MIT https://opensource.org/licenses/MIT
 *}
<form method="post" action="{$msads_form_action|escape:'html':'UTF-8'}" class="form-horizontal">
    <div class="panel">
        <div class="panel-heading">
            <i class="icon-sitemap"></i> {l s='Categories' mod='microsoftadsfeed'}
        </div>

        <p>
            {l s='Give each category the matching Microsoft product category, as an ID (4947) or as a full path. A subcategory inherits the value of its parent, so filling in the top categories is often enough.' mod='microsoftadsfeed'}
        </p>
        <p class="help-block">
            {l s='Microsoft uses the Google product taxonomy:' mod='microsoftadsfeed'}
            <a href="https://www.google.com/basepages/producttype/taxonomy-with-ids.fr-FR.txt" target="_blank" rel="noopener noreferrer">{l s='list with IDs in French' mod='microsoftadsfeed'}</a>,
            <a href="https://www.google.com/basepages/producttype/taxonomy-with-ids.en-US.txt" target="_blank" rel="noopener noreferrer">{l s='in English' mod='microsoftadsfeed'}</a>.
            {l s='Mapping and exclusion follow the default category of each product.' mod='microsoftadsfeed'}
        </p>

        <table class="table">
            <thead>
            <tr>
                <th>{l s='Category' mod='microsoftadsfeed'}</th>
                <th style="width: 10%; text-align: center;">{l s='Products' mod='microsoftadsfeed'}</th>
                <th style="width: 40%;">{l s='Microsoft category' mod='microsoftadsfeed'}</th>
                <th style="width: 10%; text-align: center;">{l s='Exclude' mod='microsoftadsfeed'}</th>
            </tr>
            </thead>
            <tbody>
            {foreach $msads_categories as $category}
                <tr{if $category.excluded || $category.parent_excluded} class="text-muted"{/if}>
                    <td style="padding-left: {($category.depth * 20 + 8)|intval}px;">
                        {$category.name|escape:'html':'UTF-8'}
                        <small class="text-muted">#{$category.id|intval}</small>
                    </td>
                    <td style="text-align: center;">{if $category.products}{$category.products|intval}{/if}</td>
                    <td>
                        <input type="text" class="form-control" maxlength="255"
                               name="msads_category[{$category.id|intval}]"
                               value="{$category.value|escape:'html':'UTF-8'}"
                               placeholder="{if $category.inherited}{$category.inherited|escape:'html':'UTF-8'}{/if}">
                    </td>
                    <td style="text-align: center;">
                        {if $category.parent_excluded}
                            <i class="icon-ban" title="{l s='Excluded with its parent category' mod='microsoftadsfeed'}"></i>
                        {else}
                            <input type="checkbox" name="msads_exclude[{$category.id|intval}]" value="1"{if $category.excluded} checked{/if}>
                        {/if}
                    </td>
                </tr>
            {/foreach}
            </tbody>
        </table>

        <div class="panel-footer">
            <button type="submit" name="submitMsAdsFeedCategories" class="btn btn-default pull-right">
                <i class="process-icon-save"></i> {l s='Save' mod='microsoftadsfeed'}
            </button>
        </div>
    </div>
</form>
