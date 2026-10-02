<?php
/**
 * Product feeds for PrestaShop: Google, Microsoft, Meta, Pinterest.
 *
 * Publishes the catalogue as the tab-separated files Google Merchant Center,
 * Microsoft Merchant Center, Meta and Pinterest download, and keeps them in
 * step with every product change.
 *
 * @author    SBINFO <contact@sbinfo.pro>
 * @copyright 2026 SBINFO
 * @license   MIT https://opensource.org/licenses/MIT
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/classes/ProductFeedConfig.php';
require_once __DIR__ . '/classes/ProductFeedText.php';
require_once __DIR__ . '/classes/ProductFeedChannel.php';
require_once __DIR__ . '/classes/ProductFeedStore.php';
require_once __DIR__ . '/classes/ProductFeedBuilder.php';

class ProductFeed extends Module
{
    /** Address of a platform's feed, relative to the shop root. */
    const ROUTE_RULE = 'product-feed/{channel}/{token}';

    /**
     * Address used by the Microsoft-only module this one replaces. Kept so
     * that the feeds already declared on the platforms keep working.
     */
    const LEGACY_ROUTE_RULE = 'microsoft-ads-feed/{token}';

    /** Hooks through which a catalogue change reaches the module. */
    const CATALOG_HOOKS = [
        'actionProductAdd',
        'actionProductUpdate',
        'actionProductDelete',
        'actionProductAttributeUpdate',
        'actionProductAttributeDelete',
        'actionUpdateQuantity',
        'actionObjectProductAddAfter',
        'actionObjectProductUpdateAfter',
        'actionObjectProductDeleteAfter',
        'actionObjectCombinationAddAfter',
        'actionObjectCombinationUpdateAfter',
        'actionObjectCombinationDeleteAfter',
        'actionObjectStockAvailableUpdateAfter',
        'actionObjectSpecificPriceAddAfter',
        'actionObjectSpecificPriceUpdateAfter',
        'actionObjectSpecificPriceDeleteAfter',
        'actionObjectSpecificPriceRuleAddAfter',
        'actionObjectSpecificPriceRuleUpdateAfter',
        'actionObjectSpecificPriceRuleDeleteAfter',
        'actionObjectImageAddAfter',
        'actionObjectImageUpdateAfter',
        'actionObjectImageDeleteAfter',
        'actionObjectCategoryUpdateAfter',
        'actionObjectCategoryDeleteAfter',
        'actionObjectManufacturerUpdateAfter',
        'actionObjectManufacturerDeleteAfter',
        'actionObjectProductAttributeUpdateAfter',
        'actionObjectProductAttributeDeleteAfter',
        'actionObjectAttributeGroupUpdateAfter',
        'actionObjectAttributeGroupDeleteAfter',
    ];

    /** @var bool the end-of-request work is already scheduled */
    private static $afterRequestScheduled = false;

    /** @var ProductFeedStore|null */
    private $store;

    /** @var array<int, string> */
    private $formErrors = [];

    public function __construct()
    {
        $this->name = 'productfeed';
        $this->tab = 'advertising_marketing';
        $this->version = '2.0.0';
        $this->author = 'SBINFO';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = ['min' => '9.0.0', 'max' => _PS_VERSION_];

        parent::__construct();

        $this->displayName = $this->l('Product feeds');
        $this->description = $this->l('Publishes your catalogue for Google Merchant Center, Microsoft Merchant Center, Meta and Pinterest, updated as soon as a product changes.');
        $this->confirmUninstall = $this->l('Remove the module? The feed addresses will stop working and the platforms will no longer receive your products.');
    }

    /**
     * @return bool
     */
    public function install()
    {
        if (!parent::install()) {
            return false;
        }

        foreach (ProductFeedConfig::DEFAULTS as $key => $default) {
            Configuration::updateValue($key, $default);
        }
        Configuration::updateValue('PRODUCTFEED_LANG', (int) Configuration::get('PS_LANG_DEFAULT'));
        Configuration::updateValue('PRODUCTFEED_IMAGE_TYPE', $this->defaultImageType());

        // Taking over from the Microsoft-only module: its settings and its
        // token come along, so nothing has to be changed on the platforms.
        ProductFeedConfig::importLegacySettings();
        ProductFeedConfig::token();

        return $this->registerHook(array_merge(['moduleRoutes'], self::CATALOG_HOOKS));
    }

    /**
     * @return bool
     */
    public function uninstall()
    {
        foreach (array_keys(ProductFeedConfig::DEFAULTS) as $key) {
            Configuration::deleteByName($key);
        }
        Configuration::deleteByName(ProductFeedConfig::TOKEN_KEY);
        $this->store()->purge();

        return parent::uninstall();
    }

    /**
     * @return ProductFeedStore
     */
    public function store()
    {
        if ($this->store === null) {
            $this->store = new ProductFeedStore($this->getLocalPath() . 'export/');
        }

        return $this->store;
    }

    /**
     * Brings the feeds of a shop up to date if needed, and returns the
     * summary of the files on disk.
     *
     * @param int $idShop
     *
     * @return array
     */
    public function ensureFeed($idShop)
    {
        @set_time_limit(300);

        return $this->store()->ensureFresh($idShop, [$this, 'buildFeed']);
    }

    /**
     * @param int $idShop
     *
     * @return array [lines per platform, summary]
     */
    public function buildFeed($idShop)
    {
        $builder = new ProductFeedBuilder($idShop);

        return $builder->build();
    }

    /**
     * Public address of a platform's feed, the one to paste on the platform.
     *
     * @param int $idShop
     * @param string $channel
     *
     * @return string
     */
    public function feedUrl($idShop, $channel)
    {
        $config = new ProductFeedConfig($idShop);

        return $this->context->link->getModuleLink(
            $this->name,
            'feed',
            ['channel' => $channel, 'token' => ProductFeedConfig::token()],
            true,
            $config->languageId(),
            $idShop
        );
    }

    /**
     * Address of the Microsoft-only module, still answered in the Microsoft
     * dialect.
     *
     * @param int $idShop
     *
     * @return string
     */
    public function legacyFeedUrl($idShop)
    {
        $config = new ProductFeedConfig($idShop);

        return $this->context->link->getPageLink(
            'module-productfeed-legacy',
            true,
            $config->languageId(),
            ['token' => ProductFeedConfig::token()],
            false,
            $idShop
        );
    }

    /**
     * Serves the feeds from addresses at the shop root: robots.txt forbids
     * the modules folder, and PrestaShop 9 refuses to serve text files there.
     *
     * @return array
     */
    public function hookModuleRoutes()
    {
        $params = ['fc' => 'module', 'module' => $this->name];
        $token = ['regexp' => '[a-f0-9]{32}', 'param' => 'token'];

        return [
            'module-productfeed-feed' => [
                'controller' => 'feed',
                'rule' => self::ROUTE_RULE,
                'keywords' => [
                    'channel' => ['regexp' => implode('|', ProductFeedChannel::all()), 'param' => 'channel'],
                    'token' => $token,
                ],
                'params' => $params,
            ],
            'module-productfeed-legacy' => [
                'controller' => 'feed',
                'rule' => self::LEGACY_ROUTE_RULE,
                'keywords' => ['token' => $token],
                'params' => $params + ['channel' => ProductFeedChannel::MICROSOFT],
            ],
        ];
    }

    /**
     * Records a catalogue change once per request.
     *
     * Back office and command line changes rebuild the files as soon as the
     * response has left. Changes made by customers, stock movements after an
     * order for instance, only mark the files as outdated: they are rebuilt
     * when a platform next downloads one, so orders never pay for a rebuild.
     */
    public function catalogChanged()
    {
        if (self::$afterRequestScheduled) {
            return;
        }
        self::$afterRequestScheduled = true;

        // Stamped now so that a download running in parallel already knows
        // a change is under way, then again once the request has finished.
        $this->store()->markChanged();
        register_shutdown_function([$this, 'afterCatalogChange']);
    }

    /**
     * @internal shutdown function
     */
    public function afterCatalogChange()
    {
        $this->store()->markChanged();

        if (!defined('_PS_ADMIN_DIR_') && !Tools::isPHPCLI()) {
            return;
        }

        ignore_user_abort(true);
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            @litespeed_finish_request();
        }
        @set_time_limit(300);

        $changesUntil = microtime(true);
        foreach (Shop::getShops(true, null, true) as $idShop) {
            try {
                $this->store()->rebuild((int) $idShop, [$this, 'buildFeed'], $changesUntil);
            } catch (Throwable $e) {
                PrestaShopLogger::addLog('Product feed: ' . $e->getMessage(), 3, null, 'Module', (int) $this->id);
            }
        }
    }

    public function hookActionProductAdd()
    {
        $this->catalogChanged();
    }

    public function hookActionProductUpdate()
    {
        $this->catalogChanged();
    }

    public function hookActionProductDelete()
    {
        $this->catalogChanged();
    }

    public function hookActionProductAttributeUpdate()
    {
        $this->catalogChanged();
    }

    public function hookActionProductAttributeDelete()
    {
        $this->catalogChanged();
    }

    public function hookActionUpdateQuantity()
    {
        $this->catalogChanged();
    }

    public function hookActionObjectProductAddAfter()
    {
        $this->catalogChanged();
    }

    public function hookActionObjectProductUpdateAfter()
    {
        $this->catalogChanged();
    }

    public function hookActionObjectProductDeleteAfter()
    {
        $this->catalogChanged();
    }

    public function hookActionObjectCombinationAddAfter()
    {
        $this->catalogChanged();
    }

    public function hookActionObjectCombinationUpdateAfter()
    {
        $this->catalogChanged();
    }

    public function hookActionObjectCombinationDeleteAfter()
    {
        $this->catalogChanged();
    }

    public function hookActionObjectStockAvailableUpdateAfter()
    {
        $this->catalogChanged();
    }

    public function hookActionObjectSpecificPriceAddAfter()
    {
        $this->catalogChanged();
    }

    public function hookActionObjectSpecificPriceUpdateAfter()
    {
        $this->catalogChanged();
    }

    public function hookActionObjectSpecificPriceDeleteAfter()
    {
        $this->catalogChanged();
    }

    public function hookActionObjectSpecificPriceRuleAddAfter()
    {
        $this->catalogChanged();
    }

    public function hookActionObjectSpecificPriceRuleUpdateAfter()
    {
        $this->catalogChanged();
    }

    public function hookActionObjectSpecificPriceRuleDeleteAfter()
    {
        $this->catalogChanged();
    }

    public function hookActionObjectImageAddAfter()
    {
        $this->catalogChanged();
    }

    public function hookActionObjectImageUpdateAfter()
    {
        $this->catalogChanged();
    }

    public function hookActionObjectImageDeleteAfter()
    {
        $this->catalogChanged();
    }

    public function hookActionObjectCategoryUpdateAfter()
    {
        $this->catalogChanged();
    }

    public function hookActionObjectCategoryDeleteAfter()
    {
        $this->catalogChanged();
    }

    public function hookActionObjectManufacturerUpdateAfter()
    {
        $this->catalogChanged();
    }

    public function hookActionObjectManufacturerDeleteAfter()
    {
        $this->catalogChanged();
    }

    public function hookActionObjectProductAttributeUpdateAfter()
    {
        $this->catalogChanged();
    }

    public function hookActionObjectProductAttributeDeleteAfter()
    {
        $this->catalogChanged();
    }

    public function hookActionObjectAttributeGroupUpdateAfter()
    {
        $this->catalogChanged();
    }

    public function hookActionObjectAttributeGroupDeleteAfter()
    {
        $this->catalogChanged();
    }

    /**
     * Configuration screen.
     *
     * @return string
     */
    public function getContent()
    {
        $output = '';
        $idShop = (int) $this->context->shop->id;

        if (!$this->store()->isWritable()) {
            $output .= $this->displayError(sprintf(
                $this->l('The folder %s must be writable by the web server.'),
                htmlspecialchars($this->store()->directory(), ENT_QUOTES, 'UTF-8')
            ));
        }

        if (Tools::isSubmit('submitProductFeedSettings')) {
            $this->saveSettings();
            $output .= $this->afterSave($idShop);
        } elseif (Tools::isSubmit('submitProductFeedCategories')) {
            $this->saveCategories();
            $output .= $this->afterSave($idShop);
        } elseif (Tools::isSubmit('submitProductFeedRebuild')) {
            $output .= $this->rebuildFromScreen($idShop)
                ? $this->displayConfirmation($this->l('The feeds have been regenerated.'))
                : '';
        } elseif (Tools::isSubmit('submitProductFeedRenewToken')) {
            ProductFeedConfig::renewToken();
            if ($this->rebuildFromScreen($idShop)) {
                $output .= $this->displayWarning($this->l('The feeds have new addresses. Replace them on every platform: the previous ones no longer work.'));
            }
        } elseif ($this->store()->isWritable() && $this->store()->meta($idShop) === null) {
            // First visit: build once so the screen can report on the catalogue.
            $this->rebuildFromScreen($idShop);
        }

        foreach ($this->formErrors as $error) {
            $output .= $this->displayError($error);
        }

        if (Module::isInstalled(ProductFeedConfig::LEGACY_MODULE)) {
            $output .= $this->displayWarning($this->l('The module "Microsoft Ads product feed" (microsoftadsfeed) is still installed. This module has taken over its settings and its address: uninstall it, then delete it from the module manager.'));
        }

        return $output
            . $this->renderStatus($idShop)
            . $this->renderSettingsForm()
            . $this->renderCategories($idShop);
    }

    /**
     * @param int $idShop
     *
     * @return string
     */
    private function afterSave($idShop)
    {
        if (!empty($this->formErrors)) {
            return '';
        }

        return $this->rebuildFromScreen($idShop)
            ? $this->displayConfirmation($this->l('Settings saved and feeds regenerated.'))
            : '';
    }

    /**
     * @param int $idShop
     *
     * @return bool
     */
    private function rebuildFromScreen($idShop)
    {
        try {
            @set_time_limit(300);
            $this->store()->rebuild($idShop, [$this, 'buildFeed']);

            return true;
        } catch (Throwable $e) {
            $this->formErrors[] = sprintf($this->l('The feeds could not be generated: %s'), htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));

            return false;
        }
    }

    private function saveSettings()
    {
        $idLang = (int) Tools::getValue('PRODUCTFEED_LANG');
        if (!Language::getLanguage($idLang)) {
            $this->formErrors[] = $this->l('Choose a language for the feed.');
        }

        $imageType = (string) Tools::getValue('PRODUCTFEED_IMAGE_TYPE');
        if ($imageType !== '' && !ImageType::typeAlreadyExists($imageType)) {
            $this->formErrors[] = $this->l('The image format does not exist.');
        }

        $amounts = [];
        foreach (['PRODUCTFEED_SHIPPING_COST', 'PRODUCTFEED_FREE_SHIPPING_FROM'] as $key) {
            $raw = trim((string) Tools::getValue($key));
            $amount = ProductFeedConfig::parseAmount($raw);
            if ($raw !== '' && $amount === null) {
                $this->formErrors[] = sprintf($this->l('%s is not a valid amount.'), htmlspecialchars($raw, ENT_QUOTES, 'UTF-8'));
            }
            $amounts[$key] = $amount === null ? '' : ProductFeedText::amount($amount);
        }

        $excludedRaw = (string) Tools::getValue('PRODUCTFEED_EXCLUDED_PRODUCTS');
        if (preg_match('/[^\d\s,;]/', $excludedRaw)) {
            $this->formErrors[] = $this->l('Excluded products: enter product IDs separated by commas.');
        }

        $attributeMap = [];
        foreach (AttributeGroup::getAttributesGroups($this->context->language->id) as $group) {
            $field = (string) Tools::getValue('PRODUCTFEED_GROUP_' . (int) $group['id_attribute_group']);
            $attributeMap[(int) $group['id_attribute_group']] = in_array($field, ProductFeedConfig::VARIANT_FIELDS, true) ? $field : '';
        }

        if (!empty($this->formErrors)) {
            return;
        }

        $outOfStock = Tools::getValue('PRODUCTFEED_OUT_OF_STOCK') === ProductFeedConfig::OUT_OF_STOCK_EXCLUDE
            ? ProductFeedConfig::OUT_OF_STOCK_EXCLUDE
            : ProductFeedConfig::OUT_OF_STOCK_INCLUDE;
        $description = Tools::getValue('PRODUCTFEED_DESCRIPTION') === ProductFeedConfig::DESCRIPTION_SHORT
            ? ProductFeedConfig::DESCRIPTION_SHORT
            : ProductFeedConfig::DESCRIPTION_LONG;

        Configuration::updateValue('PRODUCTFEED_LANG', $idLang);
        Configuration::updateValue('PRODUCTFEED_TAX_INCL', (int) (bool) Tools::getValue('PRODUCTFEED_TAX_INCL'));
        Configuration::updateValue('PRODUCTFEED_COMBINATIONS', (int) (bool) Tools::getValue('PRODUCTFEED_COMBINATIONS'));
        Configuration::updateValue('PRODUCTFEED_OUT_OF_STOCK', $outOfStock);
        Configuration::updateValue('PRODUCTFEED_DESCRIPTION', $description);
        Configuration::updateValue('PRODUCTFEED_IMAGE_TYPE', $imageType);
        Configuration::updateValue('PRODUCTFEED_DEFAULT_BRAND', ProductFeedText::plain((string) Tools::getValue('PRODUCTFEED_DEFAULT_BRAND'), ProductFeedBuilder::MAX_BRAND));
        Configuration::updateValue('PRODUCTFEED_MPN_FROM_REF', (int) (bool) Tools::getValue('PRODUCTFEED_MPN_FROM_REF'));
        Configuration::updateValue('PRODUCTFEED_SHIPPING_COST', $amounts['PRODUCTFEED_SHIPPING_COST']);
        Configuration::updateValue('PRODUCTFEED_FREE_SHIPPING_FROM', $amounts['PRODUCTFEED_FREE_SHIPPING_FROM']);
        Configuration::updateValue('PRODUCTFEED_EXCLUDED_PRODUCTS', implode(',', ProductFeedConfig::parseIds($excludedRaw)));
        Configuration::updateValue('PRODUCTFEED_GOOGLE_NO_LOCAL', (int) (bool) Tools::getValue('PRODUCTFEED_GOOGLE_NO_LOCAL'));
        Configuration::updateValue('PRODUCTFEED_ATTRIBUTE_MAP', json_encode($attributeMap));
    }

    private function saveCategories()
    {
        $map = [];
        $submitted = Tools::getValue('pf_category');
        foreach (is_array($submitted) ? $submitted : [] as $idCategory => $value) {
            $value = ProductFeedText::plain((string) $value, 255);
            if ((int) $idCategory > 0 && $value !== '') {
                $map[(int) $idCategory] = $value;
            }
        }

        $excluded = Tools::getValue('pf_exclude');
        $excluded = is_array($excluded) ? array_keys(array_filter($excluded)) : [];

        Configuration::updateValue('PRODUCTFEED_CATEGORY_MAP', json_encode($map, JSON_UNESCAPED_UNICODE));
        Configuration::updateValue('PRODUCTFEED_CATEGORY_EXCLUDED', implode(',', ProductFeedConfig::parseIds(implode(',', $excluded))));
    }

    /**
     * @param int $idShop
     *
     * @return string
     */
    private function renderStatus($idShop)
    {
        $meta = $this->store()->meta($idShop);
        $reasons = $this->reasonLabels();
        $issues = [];

        if ($meta !== null) {
            foreach ($meta['issues'] as $issue) {
                $issue['reason_label'] = isset($reasons[$issue['reason']]) ? $reasons[$issue['reason']] : $issue['reason'];
                $issue['edit_url'] = $issue['id_product'] > 0 ? $this->context->link->getAdminLink(
                    'AdminProducts',
                    true,
                    ['route' => 'admin_products_edit', 'productId' => (int) $issue['id_product']]
                ) : '';
                $issues[] = $issue;
            }
        }

        $summary = [];
        foreach (['skipped', 'warnings'] as $kind) {
            foreach ($meta !== null ? $meta[$kind] : [] as $reason => $count) {
                $summary[] = [
                    'kind' => $kind,
                    'label' => isset($reasons[$reason]) ? $reasons[$reason] : $reason,
                    'count' => (int) $count,
                ];
            }
        }

        $channels = [];
        foreach ($this->channelLabels() as $channel => $labels) {
            $channels[] = [
                'id' => $channel,
                'name' => $labels['name'],
                'help' => $labels['help'],
                'url' => $this->feedUrl($idShop, $channel),
                'size' => $meta !== null && isset($meta['size'][$channel]) ? Tools::formatBytes((int) $meta['size'][$channel], 1) : '',
            ];
        }

        $this->context->smarty->assign([
            'pf_channels' => $channels,
            'pf_legacy_url' => $this->legacyFeedUrl($idShop),
            'pf_meta' => $meta,
            'pf_generated' => $meta !== null ? Tools::displayDate(date('Y-m-d H:i:s', (int) $meta['finished_at']), true) : '',
            'pf_pending' => $meta !== null && $this->store()->isStale($idShop),
            'pf_summary' => $summary,
            'pf_issues' => $issues,
            'pf_form_action' => $this->configureUrl(),
        ]);

        return $this->context->smarty->fetch($this->getLocalPath() . 'views/templates/admin/status.tpl');
    }

    /**
     * @return string
     */
    private function renderSettingsForm()
    {
        $idLang = (int) $this->context->language->id;
        $config = new ProductFeedConfig((int) $this->context->shop->id);

        $languages = [];
        foreach (Language::getLanguages(true, (int) $this->context->shop->id) as $language) {
            $languages[] = ['id' => (int) $language['id_lang'], 'name' => $language['name']];
        }

        $imageTypes = [['id' => '', 'name' => $this->l('Original image')]];
        foreach (ImageType::getImagesTypes('products', true) as $type) {
            $imageTypes[] = [
                'id' => $type['name'],
                'name' => sprintf('%s (%d × %d)', $type['name'], (int) $type['width'], (int) $type['height']),
            ];
        }

        $currency = Currency::getCurrencyInstance((int) Configuration::get('PS_CURRENCY_DEFAULT'));

        $inputs = [
            [
                'type' => 'select',
                'label' => $this->l('Language'),
                'name' => 'PRODUCTFEED_LANG',
                'desc' => $this->l('Titles, descriptions and product addresses are taken in this language. It must match the language declared on each platform.'),
                'options' => ['query' => $languages, 'id' => 'id', 'name' => 'name'],
            ],
            $this->switchField('PRODUCTFEED_TAX_INCL', $this->l('Prices including tax'), $this->l('Required for France, Germany and the United Kingdom. Turn it off only for a store selling in the United States.')),
            $this->switchField('PRODUCTFEED_COMBINATIONS', $this->l('One line per combination'), $this->l('Each combination becomes a product of its own, with its price, stock, barcode and image. Otherwise only the default combination is sent.')),
            [
                'type' => 'select',
                'label' => $this->l('Products out of stock'),
                'name' => 'PRODUCTFEED_OUT_OF_STOCK',
                'desc' => $this->l('Google and Microsoft recommend sending them as out of stock rather than removing them: the ad pauses and restarts on its own when the stock returns.'),
                'options' => ['query' => [
                    ['id' => ProductFeedConfig::OUT_OF_STOCK_INCLUDE, 'name' => $this->l('Send them as out of stock')],
                    ['id' => ProductFeedConfig::OUT_OF_STOCK_EXCLUDE, 'name' => $this->l('Leave them out of the feed')],
                ], 'id' => 'id', 'name' => 'name'],
            ],
            [
                'type' => 'select',
                'label' => $this->l('Description'),
                'name' => 'PRODUCTFEED_DESCRIPTION',
                'desc' => $this->l('The other description is used when this one is empty. Formatting is removed.'),
                'options' => ['query' => [
                    ['id' => ProductFeedConfig::DESCRIPTION_LONG, 'name' => $this->l('Full description')],
                    ['id' => ProductFeedConfig::DESCRIPTION_SHORT, 'name' => $this->l('Summary')],
                ], 'id' => 'id', 'name' => 'name'],
            ],
            [
                'type' => 'select',
                'label' => $this->l('Image size'),
                'name' => 'PRODUCTFEED_IMAGE_TYPE',
                'desc' => $this->l('Meta asks for at least 500 × 500 pixels, Microsoft 220 × 220 and Google 100 × 100. The larger the better, as long as the image stays under 8 MB.'),
                'options' => ['query' => $imageTypes, 'id' => 'id', 'name' => 'name'],
            ],
            [
                'type' => 'text',
                'label' => $this->l('Default brand'),
                'name' => 'PRODUCTFEED_DEFAULT_BRAND',
                'desc' => $this->l('Used for products without a brand. Put your shop name only for products you make yourself.'),
            ],
            $this->switchField('PRODUCTFEED_MPN_FROM_REF', $this->l('Reference as MPN'), $this->l('When the MPN field is empty, send the product reference instead. Only makes sense for products you manufacture.')),
            [
                'type' => 'text',
                'label' => $this->l('Shipping cost'),
                'name' => 'PRODUCTFEED_SHIPPING_COST',
                'class' => 'fixed-width-sm',
                'suffix' => $currency->iso_code,
                'desc' => $this->l('Optional for France. Leave empty to use the shipping settings declared on each platform.'),
            ],
            [
                'type' => 'text',
                'label' => $this->l('Free shipping from'),
                'name' => 'PRODUCTFEED_FREE_SHIPPING_FROM',
                'class' => 'fixed-width-sm',
                'suffix' => $currency->iso_code,
                'desc' => $this->l('Products at or above this price are sent with free shipping.'),
            ],
            [
                'type' => 'text',
                'label' => $this->l('Excluded products'),
                'name' => 'PRODUCTFEED_EXCLUDED_PRODUCTS',
                'desc' => $this->l('Product IDs separated by commas. Whole categories can be excluded in the table below.'),
            ],
            $this->switchField('PRODUCTFEED_GOOGLE_NO_LOCAL', $this->l('Google: no local listings'), $this->l('For a shop without a physical store. Keeps the products away from free local listings and local inventory ads, which otherwise report missing store inventory. Removing these two add-ons in Merchant Center does the same for the whole account.')),
        ];

        $fieldOptions = [
            ['id' => '', 'name' => $this->l('Not sent')],
            ['id' => 'color', 'name' => $this->l('Color (color)')],
            ['id' => 'size', 'name' => $this->l('Size (size)')],
            ['id' => 'material', 'name' => $this->l('Material (material)')],
            ['id' => 'pattern', 'name' => $this->l('Pattern (pattern)')],
        ];
        $values = [];
        $attributeMap = $config->attributeMap();
        $groupHelp = $this->l('The platforms group the combinations of a product only when at least one attribute is sent.');
        foreach (AttributeGroup::getAttributesGroups($idLang) as $group) {
            $key = 'PRODUCTFEED_GROUP_' . (int) $group['id_attribute_group'];
            $inputs[] = [
                'type' => 'select',
                'label' => sprintf($this->l('Attribute "%s"'), htmlspecialchars($group['name'], ENT_QUOTES, 'UTF-8')),
                'name' => $key,
                'desc' => $groupHelp,
                'options' => ['query' => $fieldOptions, 'id' => 'id', 'name' => 'name'],
            ];
            $groupHelp = '';
            $values[$key] = Tools::getValue($key, $attributeMap[(int) $group['id_attribute_group']] ?? '');
        }

        foreach (array_keys(ProductFeedConfig::DEFAULTS) as $key) {
            $values[$key] = Tools::getValue($key, $config->get($key));
        }
        $values['PRODUCTFEED_LANG'] = Tools::getValue('PRODUCTFEED_LANG', $config->languageId());

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->identifier = $this->identifier;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->default_form_language = $idLang;
        $helper->submit_action = 'submitProductFeedSettings';
        $helper->tpl_vars = ['fields_value' => $values];

        return $helper->generateForm([['form' => [
            'legend' => ['title' => $this->l('Settings'), 'icon' => 'icon-cogs'],
            'input' => $inputs,
            'submit' => ['title' => $this->l('Save')],
        ]]]);
    }

    /**
     * Category table: Google category and exclusion, inherited downwards.
     *
     * @param int $idShop
     *
     * @return string
     */
    private function renderCategories($idShop)
    {
        $config = new ProductFeedConfig($idShop);
        $idLang = $config->languageId();
        $map = $config->categoryMap();
        $excluded = $config->excludedCategories();

        $rows = Db::getInstance()->executeS(
            'SELECT c.`id_category`, c.`id_parent`, c.`level_depth`, c.`is_root_category`, cl.`name`,
                (SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'product_shop` ps
                    WHERE ps.`id_category_default` = c.`id_category` AND ps.`id_shop` = ' . (int) $idShop . ' AND ps.`active` = 1) AS products
            FROM `' . _DB_PREFIX_ . 'category` c
            INNER JOIN `' . _DB_PREFIX_ . 'category_shop` cs
                ON (cs.`id_category` = c.`id_category` AND cs.`id_shop` = ' . (int) $idShop . ')
            LEFT JOIN `' . _DB_PREFIX_ . 'category_lang` cl
                ON (cl.`id_category` = c.`id_category` AND cl.`id_lang` = ' . (int) $idLang . ' AND cl.`id_shop` = ' . (int) $idShop . ')
            WHERE c.`id_parent` > 0
            ORDER BY c.`nleft` ASC'
        ) ?: [];

        $minDepth = null;
        foreach ($rows as $row) {
            $minDepth = $minDepth === null ? (int) $row['level_depth'] : min($minDepth, (int) $row['level_depth']);
        }

        // Rows come in tree order, so a parent is always resolved first.
        $inherited = [];
        $inheritedExclusion = [];
        $categories = [];
        foreach ($rows as $row) {
            $id = (int) $row['id_category'];
            $parent = (int) $row['id_parent'];
            $fromParent = isset($inherited[$parent]) ? $inherited[$parent] : '';
            $inherited[$id] = isset($map[$id]) ? $map[$id] : $fromParent;
            $parentExcluded = !empty($inheritedExclusion[$parent]);
            $inheritedExclusion[$id] = $parentExcluded || isset($excluded[$id]);

            $categories[] = [
                'id' => $id,
                'name' => $row['name'],
                'depth' => (int) $row['level_depth'] - (int) $minDepth,
                'products' => (int) $row['products'],
                'value' => isset($map[$id]) ? $map[$id] : '',
                'inherited' => $fromParent,
                'excluded' => isset($excluded[$id]),
                'parent_excluded' => $parentExcluded,
            ];
        }

        $this->context->smarty->assign([
            'pf_categories' => $categories,
            'pf_form_action' => $this->configureUrl(),
        ]);

        return $this->context->smarty->fetch($this->getLocalPath() . 'views/templates/admin/categories.tpl');
    }

    /**
     * @return array<string, string>
     */
    private function reasonLabels()
    {
        return [
            'excluded_product' => $this->l('Excluded in the settings'),
            'excluded_category' => $this->l('Category excluded'),
            'no_name' => $this->l('No name in the feed language'),
            'not_for_sale' => $this->l('Not available for order or price hidden'),
            'no_price' => $this->l('Price is zero'),
            'no_image' => $this->l('No image'),
            'out_of_stock' => $this->l('Out of stock'),
            'error' => $this->l('Error while reading the product'),
            'no_category' => $this->l('No Google category'),
            'no_brand' => $this->l('No brand: Meta rejects the product, Google and Microsoft show it less'),
            'invalid_gtin' => $this->l('Barcode rejected (wrong length, check digit or reserved range)'),
            'no_identifier' => $this->l('No barcode, nor brand and MPN: sent as a product without identifier'),
        ];
    }

    /**
     * Platforms in the order shown, with where to paste each address.
     *
     * @return array<string, array<string, string>>
     */
    private function channelLabels()
    {
        return [
            ProductFeedChannel::GOOGLE => [
                'name' => 'Google Merchant Center',
                'help' => $this->l('Products, Data sources, Add a product source, File: enter the address. Also suits other services that read Google Shopping feeds.'),
            ],
            ProductFeedChannel::MICROSOFT => [
                'name' => 'Microsoft Merchant Center',
                'help' => $this->l('Feeds, Create feed, input method "Automatically download file from URL".'),
            ],
            ProductFeedChannel::META => [
                'name' => 'Meta (Facebook, Instagram)',
                'help' => $this->l('Commerce Manager, Catalogue, Data sources, Data feed, Scheduled feed.'),
            ],
            ProductFeedChannel::PINTEREST => [
                'name' => 'Pinterest',
                'help' => $this->l('Ads, Catalogues, Add data source: enter the address.'),
            ],
        ];
    }

    /**
     * @return string
     */
    private function configureUrl()
    {
        return $this->context->link->getAdminLink('AdminModules', true, [], ['configure' => $this->name]);
    }

    /**
     * Largest product image format, which suits every platform.
     *
     * @return string
     */
    private function defaultImageType()
    {
        $types = ImageType::getImagesTypes('products', true);
        foreach ($types as $type) {
            if ($type['name'] === ImageType::getFormattedName('large')) {
                return $type['name'];
            }
        }
        $largest = end($types);

        return $largest ? $largest['name'] : '';
    }

    /**
     * @param string $name
     * @param string $label
     * @param string $description
     *
     * @return array
     */
    private function switchField($name, $label, $description)
    {
        return [
            'type' => 'switch',
            'label' => $label,
            'name' => $name,
            'desc' => $description,
            'is_bool' => true,
            'values' => [
                ['id' => $name . '_on', 'value' => 1, 'label' => $this->l('Yes')],
                ['id' => $name . '_off', 'value' => 0, 'label' => $this->l('No')],
            ],
        ];
    }
}
