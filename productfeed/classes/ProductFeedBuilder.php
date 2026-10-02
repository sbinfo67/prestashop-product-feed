<?php
/**
 * Product feeds for PrestaShop: Google, Microsoft, Meta, Pinterest, TikTok.
 *
 * @author    SBINFO <contact@sbinfo.pro>
 * @copyright 2026 SBINFO
 * @license   MIT https://opensource.org/licenses/MIT
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Reads the catalogue of one shop and produces one tab-separated file per
 * platform (see ProductFeedChannel).
 *
 * Prices are computed the way the product page computes them for a visitor
 * who is not logged in, because the platforms compare both.
 */
class ProductFeedBuilder
{
    const MAX_TITLE = 150;
    const MAX_DESCRIPTION = 10000;
    const MAX_BRAND = 70;
    const MAX_MPN = 70;
    const MAX_PRODUCT_TYPE = 750;
    const MAX_VARIANT = 100;
    const MAX_ADDITIONAL_IMAGES = 10;

    /** Detailed problems kept for the back office; counters stay exact. */
    const REPORT_LIMIT = 200;

    /** @var int */
    private $idShop;

    /** @var ProductFeedConfig */
    private $config;

    /** @var int */
    private $idLang;

    /** @var Link */
    private $link;

    /** @var Currency */
    private $currency;

    /** @var DateTimeZone */
    private $timezone;

    /** @var bool */
    private $stockManagement;

    /** @var string|null */
    private $imageType;

    /** @var array<int, array> id => [id_parent, is_root_category, name] */
    private $categories = [];

    /** @var array<int, array> resolved default categories */
    private $categoryCache = [];

    /** @var array<int, string> */
    private $categoryMap;

    /** @var array<int, string> */
    private $productCategoryMap;

    /** @var string */
    private $directory;

    /** @var array<int, true> */
    private $excludedCategories;

    /** @var array<int, true> */
    private $excludedProducts;

    /** @var array<int, string> */
    private $attributeMap;

    /** @var array */
    private $report;

    /**
     * @param int $idShop
     * @param string $directory folder of the generated files, for the taxonomy cache
     */
    public function __construct($idShop, $directory)
    {
        $this->idShop = (int) $idShop;
        $this->directory = $directory;
        $this->config = new ProductFeedConfig($this->idShop);
    }

    /**
     * @return array [lines of each platform's file, summary for the back office]
     */
    public function build()
    {
        // The build often runs at the end of the request that changed the
        // catalogue. Prices and objects cached earlier in that request, by
        // the faceted search indexer for instance, predate the change.
        Cache::clean('*');
        Product::resetStaticCache();
        SpecificPrice::resetStaticCache();

        $this->idLang = $this->config->languageId();
        $saved = $this->enterShopContext();

        try {
            return $this->collect();
        } finally {
            $this->leaveShopContext($saved);
        }
    }

    /**
     * @return array
     */
    private function collect()
    {
        $this->report = ['products' => 0, 'offers' => 0, 'skipped' => [], 'warnings' => [], 'issues' => []];
        $this->loadSettings();
        $this->loadCategories();

        $rows = [];
        foreach ($this->productRows() as $productRow) {
            ++$this->report['products'];

            try {
                foreach ($this->offers($productRow) as $offer) {
                    $rows[] = $offer;
                }
            } catch (Throwable $e) {
                // One broken product must not take the whole feed down.
                $this->note('skipped', 'error', (int) $productRow['id_product'], '', $e->getMessage());
            }
        }

        $this->report['offers'] = count($rows);
        $this->report['language'] = Language::getIsoById($this->idLang);
        $this->report['currency'] = $this->currency->iso_code;

        $country = Country::getIsoById((int) Configuration::get('PS_COUNTRY_DEFAULT', null, null, $this->idShop));
        $taxonomy = new ProductFeedTaxonomy($this->directory);
        $files = [];
        foreach (ProductFeedChannel::all() as $channel) {
            list($files[$channel], $this->report['columns'][$channel]) = ProductFeedChannel::render(
                $channel,
                $rows,
                $this->currency->iso_code,
                $country,
                $taxonomy
            );
        }

        return [$files, $this->report];
    }

    /**
     * One offer per combination, or a single offer for the product.
     *
     * @param array $productRow
     *
     * @return array<int, array>
     */
    private function offers(array $productRow)
    {
        $idProduct = (int) $productRow['id_product'];

        if (isset($this->excludedProducts[$idProduct])) {
            $this->note('skipped', 'excluded_product', $idProduct);

            return [];
        }

        $category = $this->category((int) $productRow['id_category_default']);
        if ($category['excluded']) {
            $this->note('skipped', 'excluded_category', $idProduct);

            return [];
        }

        $product = new Product($idProduct, false, $this->idLang, $this->idShop);
        if (!Validate::isLoadedObject($product)) {
            return [];
        }

        $name = ProductFeedText::plain($product->name, self::MAX_TITLE);
        if ($name === '') {
            $this->note('skipped', 'no_name', $idProduct);

            return [];
        }

        if (!$product->available_for_order || !$product->show_price) {
            $this->note('skipped', 'not_for_sale', $idProduct, $name);

            return [];
        }

        $shared = [
            'name' => $name,
            'description' => $this->description($product, $name),
            'brand' => $this->brand($product),
            'category' => isset($this->productCategoryMap[$idProduct]) ? $this->productCategoryMap[$idProduct] : $category['taxonomy'],
            'product_type' => $category['path'],
            'condition' => in_array($product->condition, ['new', 'used', 'refurbished'], true) ? $product->condition : 'new',
            'images' => $this->productImages($idProduct),
        ];

        if ($shared['category'] === '') {
            $this->note('warnings', 'no_category', $idProduct, $name);
        }
        if ($shared['brand'] === '') {
            $this->note('warnings', 'no_brand', $idProduct, $name);
        }

        $combinations = $this->config->exportCombinations() ? $this->combinations($idProduct) : [];
        if (empty($combinations)) {
            $offer = $this->offer($product, $shared, 0, null);

            return $offer === null ? [] : [$offer];
        }

        $combinationImages = $this->combinationImages($idProduct);
        $offers = [];
        foreach ($combinations as $idProductAttribute => $combination) {
            $combination['images'] = isset($combinationImages[$idProductAttribute])
                ? array_values(array_filter($shared['images'], function ($idImage) use ($combinationImages, $idProductAttribute) {
                    return isset($combinationImages[$idProductAttribute][$idImage]);
                }))
                : [];

            $offer = $this->offer($product, $shared, $idProductAttribute, $combination);
            if ($offer !== null) {
                $offers[] = $offer;
            }
        }

        return $offers;
    }

    /**
     * @param Product $product
     * @param array $shared values common to every combination
     * @param int $idProductAttribute 0 for the product itself
     * @param array|null $combination
     *
     * @return array|null null when the offer cannot be exported
     */
    private function offer(Product $product, array $shared, $idProductAttribute, $combination)
    {
        $idProduct = (int) $product->id;
        $variant = $this->variantValues($combination);
        $suffix = '';

        if ($combination !== null) {
            $names = array_column($combination['attributes'], 'name');
            if (!empty($names)) {
                $suffix = ProductFeedText::truncate(' - ' . implode(', ', $names), (int) (self::MAX_TITLE / 2));
            }
        }
        // The name gives way first, so two combinations never share a title.
        $title = ProductFeedText::truncate($shared['name'], self::MAX_TITLE - mb_strlen($suffix)) . $suffix;
        $label = $shared['name'] . $suffix;

        // Null lets PrestaShop pick the default combination, as the product
        // page does when no combination is given in the address.
        $priceAttribute = $combination === null ? null : (int) $idProductAttribute;
        $regular = $this->price($idProduct, $priceAttribute, false, $unused);
        $final = $this->price($idProduct, $priceAttribute, true, $specificPrice);

        if ($regular <= 0 || $final <= 0) {
            $this->note('skipped', 'no_price', $idProduct, $label);

            return null;
        }

        $images = $combination !== null && !empty($combination['images']) ? $combination['images'] : $shared['images'];
        if (empty($images)) {
            $this->note('skipped', 'no_image', $idProduct, $label);

            return null;
        }

        $availability = $this->availability($product, (int) $idProductAttribute, $combination);
        if ($availability === 'out of stock' && $this->config->excludeOutOfStock()) {
            $this->note('skipped', 'out_of_stock', $idProduct, $label);

            return null;
        }

        $source = $combination === null ? [
            'ean13' => $product->ean13,
            'isbn' => $product->isbn,
            'upc' => $product->upc,
            'mpn' => $product->mpn,
            'reference' => $product->reference,
        ] : $combination;

        $gtin = '';
        foreach (['ean13', 'isbn', 'upc'] as $field) {
            $candidate = trim((string) $source[$field]);
            if ($candidate === '') {
                continue;
            }
            $gtin = ProductFeedText::gtin($candidate);
            if ($gtin === '') {
                $this->note('warnings', 'invalid_gtin', $idProduct, $label, $candidate);
            }
            break;
        }

        $mpn = trim((string) $source['mpn']);
        if ($mpn === '' && $this->config->referenceAsMpn()) {
            $mpn = trim((string) $source['reference']);
        }
        $mpn = ProductFeedText::plain($mpn, self::MAX_MPN);

        $identified = $gtin !== '' || ($mpn !== '' && $shared['brand'] !== '');
        if (!$identified) {
            $mpn = '';
            $this->note('warnings', 'no_identifier', $idProduct, $label);
        }

        $salePrice = null;
        $saleWindow = '';
        if ($final < $regular - 0.005) {
            $salePrice = $final;
            if (is_array($specificPrice) && isset($specificPrice['from'], $specificPrice['to'])) {
                $saleWindow = ProductFeedText::saleWindow($specificPrice['from'], $specificPrice['to'], $this->timezone);
            }
        }

        $imageUrls = [];
        foreach (array_slice($images, 0, self::MAX_ADDITIONAL_IMAGES + 1) as $idImage) {
            $imageUrls[] = ProductFeedText::url(
                $this->link->getImageLink($product->link_rewrite, (int) $idImage, $this->imageType)
            );
        }
        $link = $this->link->getProductLink(
            $product, null, null, null, $this->idLang, $this->idShop,
            $combination === null ? null : (int) $idProductAttribute, false, false, true
        );

        return [
            'id' => $combination === null ? (string) $idProduct : $idProduct . '-' . (int) $idProductAttribute,
            'item_group_id' => $combination !== null && !empty(array_filter($variant)) ? (string) $idProduct : '',
            'title' => $title,
            'description' => $shared['description'],
            'link' => ProductFeedText::url($link),
            'image_link' => array_shift($imageUrls),
            'additional_image_link' => implode(',', $imageUrls),
            'price' => $regular,
            'sale_price' => $salePrice,
            'sale_price_effective_date' => $saleWindow,
            'brand' => $shared['brand'],
            'gtin' => $gtin,
            'mpn' => $mpn,
            'identified' => $identified,
            'category' => $shared['category'],
            'product_type' => $shared['product_type'],
            'color' => $variant['color'],
            'size' => $variant['size'],
            'material' => $variant['material'],
            'pattern' => $variant['pattern'],
            'shipping' => $this->shipping($product, $final),
            'availability' => $availability,
            'availability_date' => $availability === 'preorder' ? $this->availableDate($product, $combination) : '',
            'without_local' => $this->config->googleWithoutLocal(),
            'condition' => $shared['condition'],
        ];
    }

    /**
     * @param int $idProduct
     * @param int|null $idProductAttribute
     * @param bool $withReductions
     * @param mixed $specificPrice receives the special price applied, if any
     *
     * @return float
     */
    private function price($idProduct, $idProductAttribute, $withReductions, &$specificPrice)
    {
        $specificPrice = null;
        $price = Product::getPriceStatic(
            $idProduct,
            $this->config->taxIncluded(),
            $idProductAttribute,
            6,
            null,
            false,
            $withReductions,
            1,
            false,
            null,
            null,
            null,
            $specificPrice,
            true,
            true,
            Context::getContext()
        );

        return (float) Tools::ps_round((float) $price, 2);
    }

    /**
     * Mirrors the availability the product page announces to crawlers.
     *
     * @param Product $product
     * @param int $idProductAttribute
     * @param array|null $combination
     *
     * @return string
     */
    private function availability(Product $product, $idProductAttribute, $combination)
    {
        if (!$this->stockManagement) {
            return 'in stock';
        }

        $quantity = (int) StockAvailable::getQuantityAvailableByProduct((int) $product->id, $idProductAttribute, $this->idShop);
        if ($quantity > 0) {
            return 'in stock';
        }

        if (!Product::isAvailableWhenOutOfStock(StockAvailable::outOfStock((int) $product->id, $this->idShop))) {
            return 'out of stock';
        }

        // Orders are accepted while out of stock: a release date still to
        // come makes it a preorder, otherwise the shop keeps selling.
        return $this->availableDate($product, $combination) !== '' ? 'preorder' : 'in stock';
    }

    /**
     * Release date still to come, in ISO 8601, or an empty string.
     *
     * @param Product $product
     * @param array|null $combination
     *
     * @return string
     */
    private function availableDate(Product $product, $combination)
    {
        $date = $combination !== null ? (string) $combination['available_date'] : (string) $product->available_date;
        if ($date === '' || strpos($date, '0000-00-00') === 0 || $date <= date('Y-m-d')) {
            return '';
        }

        return (new DateTime($date, $this->timezone))->format('Y-m-d\TH:iP');
    }

    /**
     * @param Product $product
     * @param float $price
     *
     * @return float|null null when no shipping cost is configured
     */
    private function shipping(Product $product, $price)
    {
        $cost = $this->config->shippingCost();
        if ($cost === null) {
            return null;
        }

        $freeFrom = $this->config->freeShippingFrom();
        if ($product->is_virtual || ($freeFrom !== null && $price >= $freeFrom)) {
            return 0.0;
        }

        return $cost;
    }

    /**
     * @param Product $product
     * @param string $fallback
     *
     * @return string
     */
    private function description(Product $product, $fallback)
    {
        $sources = [$product->description, $product->description_short];
        if ($this->config->preferShortDescription()) {
            $sources = array_reverse($sources);
        }

        foreach ($sources as $source) {
            $text = ProductFeedText::plain((string) $source, self::MAX_DESCRIPTION);
            if ($text !== '') {
                return $text;
            }
        }

        return $fallback;
    }

    /**
     * @param Product $product
     *
     * @return string
     */
    private function brand(Product $product)
    {
        $brand = $product->id_manufacturer ? (string) Manufacturer::getNameById((int) $product->id_manufacturer) : '';
        if (trim($brand) === '') {
            $brand = $this->config->defaultBrand();
        }

        return ProductFeedText::plain($brand, self::MAX_BRAND);
    }

    /**
     * @param array|null $combination
     *
     * @return array<string, string>
     */
    private function variantValues($combination)
    {
        $values = array_fill_keys(ProductFeedConfig::VARIANT_FIELDS, []);

        if ($combination !== null) {
            foreach ($combination['attributes'] as $attribute) {
                $field = isset($this->attributeMap[$attribute['group']]) ? $this->attributeMap[$attribute['group']] : '';
                if ($field !== '') {
                    $values[$field][] = $attribute['name'];
                }
            }
        }

        $result = [];
        foreach ($values as $field => $list) {
            // Microsoft reads up to three colours or materials split by slashes.
            $list = in_array($field, ['color', 'material'], true) ? array_slice($list, 0, 3) : $list;
            $separator = in_array($field, ['color', 'material'], true) ? '/' : ' ';
            $result[$field] = ProductFeedText::truncate(implode($separator, array_filter($list)), self::MAX_VARIANT);
        }

        return $result;
    }

    /**
     * Mapping, exclusion and breadcrumb of a default category, walking up
     * the tree: the closest mapped ancestor gives the Microsoft category.
     *
     * @param int $idCategory
     *
     * @return array
     */
    private function category($idCategory)
    {
        if (isset($this->categoryCache[$idCategory])) {
            return $this->categoryCache[$idCategory];
        }

        $names = [];
        $taxonomy = '';
        $excluded = false;
        $cursor = $idCategory;
        $guard = 0;

        while ($cursor > 0 && isset($this->categories[$cursor]) && $guard++ < 100) {
            $current = $this->categories[$cursor];

            if ($taxonomy === '' && isset($this->categoryMap[$cursor])) {
                $taxonomy = $this->categoryMap[$cursor];
            }
            if (isset($this->excludedCategories[$cursor])) {
                $excluded = true;
            }
            // Neither the invisible root nor the home category is part of
            // the breadcrumb a shopper sees.
            if ($current['id_parent'] > 0 && !$current['is_root_category'] && $current['name'] !== '') {
                $names[] = str_replace('>', '-', $current['name']);
            }

            $cursor = $current['id_parent'];
        }

        return $this->categoryCache[$idCategory] = [
            'taxonomy' => $taxonomy,
            'excluded' => $excluded,
            'path' => ProductFeedText::truncate(implode(' > ', array_reverse($names)), self::MAX_PRODUCT_TYPE),
        ];
    }

    /**
     * Active products of the shop, drafts and hidden products left out.
     *
     * @return array
     */
    private function productRows()
    {
        return Db::getInstance()->executeS(
            'SELECT p.`id_product`, ps.`id_category_default`
            FROM `' . _DB_PREFIX_ . 'product` p
            INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps
                ON (ps.`id_product` = p.`id_product` AND ps.`id_shop` = ' . $this->idShop . ')
            WHERE ps.`active` = 1
                AND ps.`visibility` IN (\'both\', \'catalog\', \'search\')
                AND p.`state` = ' . (int) Product::STATE_SAVED . '
            ORDER BY p.`id_product` ASC'
        ) ?: [];
    }

    /**
     * Images of the product in this shop, cover first.
     *
     * @param int $idProduct
     *
     * @return array<int, int>
     */
    private function productImages($idProduct)
    {
        $rows = Db::getInstance()->executeS(
            'SELECT i.`id_image`
            FROM `' . _DB_PREFIX_ . 'image` i
            INNER JOIN `' . _DB_PREFIX_ . 'image_shop` ims
                ON (ims.`id_image` = i.`id_image` AND ims.`id_shop` = ' . $this->idShop . ')
            WHERE i.`id_product` = ' . (int) $idProduct . '
            ORDER BY ims.`cover` DESC, i.`position` ASC'
        ) ?: [];

        return array_map('intval', array_column($rows, 'id_image'));
    }

    /**
     * @param int $idProduct
     *
     * @return array<int, array<int, true>> combination => set of image IDs
     */
    private function combinationImages($idProduct)
    {
        $rows = Db::getInstance()->executeS(
            'SELECT pai.`id_product_attribute`, pai.`id_image`
            FROM `' . _DB_PREFIX_ . 'product_attribute_image` pai
            INNER JOIN `' . _DB_PREFIX_ . 'image` i ON (i.`id_image` = pai.`id_image`)
            WHERE i.`id_product` = ' . (int) $idProduct
        ) ?: [];

        $images = [];
        foreach ($rows as $row) {
            $images[(int) $row['id_product_attribute']][(int) $row['id_image']] = true;
        }

        return $images;
    }

    /**
     * Combinations of the product in this shop with their attributes.
     *
     * @param int $idProduct
     *
     * @return array<int, array>
     */
    private function combinations($idProduct)
    {
        if (!Combination::isFeatureActive()) {
            return [];
        }

        $rows = Db::getInstance()->executeS(
            'SELECT pa.`id_product_attribute`, pa.`reference`, pa.`ean13`, pa.`isbn`, pa.`upc`, pa.`mpn`,
                pas.`available_date`, a.`id_attribute_group`, al.`name` AS attribute_name
            FROM `' . _DB_PREFIX_ . 'product_attribute` pa
            INNER JOIN `' . _DB_PREFIX_ . 'product_attribute_shop` pas
                ON (pas.`id_product_attribute` = pa.`id_product_attribute` AND pas.`id_shop` = ' . $this->idShop . ')
            LEFT JOIN `' . _DB_PREFIX_ . 'product_attribute_combination` pac
                ON (pac.`id_product_attribute` = pa.`id_product_attribute`)
            LEFT JOIN `' . _DB_PREFIX_ . 'attribute` a ON (a.`id_attribute` = pac.`id_attribute`)
            LEFT JOIN `' . _DB_PREFIX_ . 'attribute_group` ag ON (ag.`id_attribute_group` = a.`id_attribute_group`)
            LEFT JOIN `' . _DB_PREFIX_ . 'attribute_lang` al
                ON (al.`id_attribute` = a.`id_attribute` AND al.`id_lang` = ' . $this->idLang . ')
            WHERE pa.`id_product` = ' . (int) $idProduct . '
            ORDER BY pa.`id_product_attribute` ASC, ag.`position` ASC, a.`position` ASC'
        ) ?: [];

        $combinations = [];
        foreach ($rows as $row) {
            $id = (int) $row['id_product_attribute'];
            if (!isset($combinations[$id])) {
                $combinations[$id] = [
                    'reference' => $row['reference'],
                    'ean13' => $row['ean13'],
                    'isbn' => $row['isbn'],
                    'upc' => $row['upc'],
                    'mpn' => $row['mpn'],
                    'available_date' => $row['available_date'],
                    'attributes' => [],
                ];
            }
            $attributeName = ProductFeedText::plain((string) $row['attribute_name'], self::MAX_VARIANT);
            if ($row['id_attribute_group'] !== null && $attributeName !== '') {
                $combinations[$id]['attributes'][] = [
                    'group' => (int) $row['id_attribute_group'],
                    'name' => $attributeName,
                ];
            }
        }

        return $combinations;
    }

    private function loadSettings()
    {
        $this->categoryMap = $this->config->categoryMap();
        $this->productCategoryMap = $this->config->productCategoryMap();
        $this->excludedCategories = $this->config->excludedCategories();
        $this->excludedProducts = $this->config->excludedProducts();
        $this->attributeMap = $this->config->attributeMap();
        $this->stockManagement = (bool) Configuration::get('PS_STOCK_MANAGEMENT', null, null, $this->idShop);

        $imageType = $this->config->imageType();
        $this->imageType = $imageType !== '' && ImageType::typeAlreadyExists($imageType) ? $imageType : null;

        try {
            $this->timezone = new DateTimeZone((string) Configuration::get('PS_TIMEZONE', null, null, $this->idShop));
        } catch (Exception $e) {
            $this->timezone = new DateTimeZone(date_default_timezone_get());
        }
    }

    private function loadCategories()
    {
        $rows = Db::getInstance()->executeS(
            'SELECT c.`id_category`, c.`id_parent`, c.`is_root_category`, cl.`name`
            FROM `' . _DB_PREFIX_ . 'category` c
            INNER JOIN `' . _DB_PREFIX_ . 'category_shop` cs
                ON (cs.`id_category` = c.`id_category` AND cs.`id_shop` = ' . $this->idShop . ')
            LEFT JOIN `' . _DB_PREFIX_ . 'category_lang` cl
                ON (cl.`id_category` = c.`id_category` AND cl.`id_lang` = ' . $this->idLang . ' AND cl.`id_shop` = ' . $this->idShop . ')'
        ) ?: [];

        foreach ($rows as $row) {
            $this->categories[(int) $row['id_category']] = [
                'id_parent' => (int) $row['id_parent'],
                'is_root_category' => (bool) $row['is_root_category'],
                'name' => ProductFeedText::plain((string) $row['name'], 200),
            ];
        }
    }

    /**
     * Counts a skipped offer or a warning, and keeps the first ones in detail.
     *
     * @param string $kind skipped or warnings
     * @param string $reason
     * @param int $idProduct
     * @param string $label
     * @param string $detail
     */
    private function note($kind, $reason, $idProduct, $label = '', $detail = '')
    {
        if (!isset($this->report[$kind][$reason])) {
            $this->report[$kind][$reason] = 0;
        }
        ++$this->report[$kind][$reason];

        if (count($this->report['issues']) < self::REPORT_LIMIT) {
            $this->report['issues'][] = [
                'kind' => $kind,
                'reason' => $reason,
                'id_product' => (int) $idProduct,
                'label' => $label,
                'detail' => ProductFeedText::truncate((string) $detail, 200),
            ];
        }
    }

    /**
     * Puts the shop, its default currency and country, and an anonymous
     * visitor in the context, as the product page would see them.
     *
     * @return array what to put back afterwards
     */
    private function enterShopContext()
    {
        $context = Context::getContext();
        $saved = [
            'shop' => $context->shop,
            'language' => $context->language,
            'currency' => $context->currency,
            'country' => $context->country,
            'cart' => $context->cart,
            'customer' => $context->customer,
            'link' => $context->link,
            'shop_context' => Shop::getContext(),
            'shop_id' => Shop::getContextShopID(),
            'shop_group_id' => Shop::getContextShopGroupID(),
        ];

        $context->shop = new Shop($this->idShop);
        Shop::setContext(Shop::CONTEXT_SHOP, $this->idShop);

        $idCurrency = (int) Configuration::get('PS_CURRENCY_DEFAULT', null, null, $this->idShop);
        $this->currency = Currency::getCurrencyInstance($idCurrency);

        $context->language = new Language($this->idLang);
        $context->currency = $this->currency;
        $context->country = new Country((int) Configuration::get('PS_COUNTRY_DEFAULT', null, null, $this->idShop), $this->idLang);
        $context->cart = new Cart();
        $context->customer = new Customer();

        $protocol = Configuration::get('PS_SSL_ENABLED', null, null, $this->idShop) ? 'https://' : 'http://';
        $this->link = new Link($protocol, $protocol);
        $context->link = $this->link;

        return $saved;
    }

    /**
     * @param array $saved
     */
    private function leaveShopContext(array $saved)
    {
        $context = Context::getContext();
        foreach (['shop', 'language', 'currency', 'country', 'cart', 'customer', 'link'] as $property) {
            $context->{$property} = $saved[$property];
        }

        switch ($saved['shop_context']) {
            case Shop::CONTEXT_SHOP:
                Shop::setContext(Shop::CONTEXT_SHOP, $saved['shop_id']);
                break;
            case Shop::CONTEXT_GROUP:
                Shop::setContext(Shop::CONTEXT_GROUP, $saved['shop_group_id']);
                break;
            case Shop::CONTEXT_ALL:
                Shop::setContext(Shop::CONTEXT_ALL);
                break;
        }
    }
}
