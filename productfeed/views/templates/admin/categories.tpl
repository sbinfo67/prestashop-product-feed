{**
 * Product feeds for PrestaShop: Google, Microsoft, Meta, Pinterest, TikTok.
 *
 * @author    SBINFO <contact@sbinfo.pro>
 * @copyright 2026 SBINFO
 * @license   MIT https://opensource.org/licenses/MIT
 *}
<form method="post" action="{$pf_form_action|escape:'html':'UTF-8'}" class="form-horizontal"
      onsubmit="var f = this.querySelectorAll('input[data-pf-product]'); for (var i = 0; i < f.length; i++) { if (f[i].value.trim() === '' && f[i].defaultValue === '') { f[i].disabled = true; } }">
    <div class="panel">
        <div class="panel-heading">
            <i class="icon-sitemap"></i> {l s='Categories' mod='productfeed'}
        </div>

        <p>
            {l s='Give each category the matching Google product category, as an ID (4947) or as a full path. Google, Microsoft, Meta, Pinterest and TikTok all use this taxonomy. A subcategory inherits the value of its parent, so filling in the top categories is often enough.' mod='productfeed'}
        </p>
        <p class="help-block">
            {l s='Google product taxonomy:' mod='productfeed'}
            <a href="https://www.google.com/basepages/producttype/taxonomy-with-ids.fr-FR.txt" target="_blank" rel="noopener noreferrer">{l s='list with IDs in French' mod='productfeed'}</a>,
            <a href="https://www.google.com/basepages/producttype/taxonomy-with-ids.en-US.txt" target="_blank" rel="noopener noreferrer">{l s='in English' mod='productfeed'}</a>.
            {l s='Mapping and exclusion follow the default category of each product.' mod='productfeed'}
        </p>

        <table class="table">
            <thead>
            <tr>
                <th>{l s='Category' mod='productfeed'}</th>
                <th style="width: 10%; text-align: center;">{l s='Products' mod='productfeed'}</th>
                <th style="width: 40%;">{l s='Google category' mod='productfeed'}</th>
                <th style="width: 10%; text-align: center;">{l s='Exclude' mod='productfeed'}</th>
            </tr>
            </thead>
            <tbody>
            {foreach $pf_categories as $category}
                <tr{if $category.excluded || $category.parent_excluded} class="text-muted"{/if}>
                    <td style="padding-left: {($category.depth * 20 + 8)|intval}px;">
                        {$category.name|escape:'html':'UTF-8'}
                        <small class="text-muted">#{$category.id|intval}</small>
                    </td>
                    <td style="text-align: center;">{if $category.products}{$category.products|intval}{/if}</td>
                    <td>
                        <input type="text" class="form-control" maxlength="255"
                               name="pf_category[{$category.id|intval}]"
                               value="{$category.value|escape:'html':'UTF-8'}"
                               placeholder="{if $category.inherited}{$category.inherited|escape:'html':'UTF-8'}{/if}">
                    </td>
                    <td style="text-align: center;">
                        {if $category.parent_excluded}
                            <i class="icon-ban" title="{l s='Excluded with its parent category' mod='productfeed'}"></i>
                        {else}
                            <input type="checkbox" name="pf_exclude[{$category.id|intval}]" value="1"{if $category.excluded} checked{/if}>
                        {/if}
                    </td>
                </tr>
            {/foreach}
            </tbody>
        </table>

        <details style="margin-top: 20px;"{if $pf_product_overrides} open{/if}>
            <summary style="cursor: pointer; font-weight: bold;">
                {l s='Category per product' mod='productfeed'}
                {if $pf_product_overrides}({$pf_product_overrides|intval}){/if}
            </summary>
            <p class="help-block">
                {l s='Optional. For a product that does not fit the category of its section, enter its own Google category; it takes precedence over the table above. Empty fields keep the category shown in grey.' mod='productfeed'}
            </p>
            <table class="table">
                <thead>
                <tr>
                    <th>{l s='Product' mod='productfeed'}</th>
                    <th>{l s='Default category' mod='productfeed'}</th>
                    <th style="width: 40%;">{l s='Google category' mod='productfeed'}</th>
                </tr>
                </thead>
                <tbody>
                {foreach $pf_products as $product}
                    <tr>
                        <td>
                            {$product.name|escape:'html':'UTF-8'}
                            <small class="text-muted">#{$product.id|intval}</small>
                        </td>
                        <td>{$product.category|escape:'html':'UTF-8'}</td>
                        <td>
                            <input type="text" class="form-control" maxlength="255" data-pf-product="1"
                                   name="pf_product[{$product.id|intval}]"
                                   value="{$product.value|escape:'html':'UTF-8'}"
                                   placeholder="{if $product.inherited}{$product.inherited|escape:'html':'UTF-8'}{/if}">
                        </td>
                    </tr>
                {/foreach}
                </tbody>
            </table>
        </details>

        <div class="panel-footer">
            <button type="submit" name="submitMsAdsFeedCategories" class="btn btn-default pull-right">
                <i class="process-icon-save"></i> {l s='Save' mod='productfeed'}
            </button>
        </div>
    </div>
</form>
