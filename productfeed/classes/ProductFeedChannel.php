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
 * The platforms the catalogue is published for. They read nearly the same
 * file, but differ on a handful of details: the separator, the name of the
 * identifier and category columns, the spelling of availability and
 * identifier values, whether a sale price carries its currency, and the
 * shipping syntax.
 *
 * One offer is computed once, then written out in each platform's dialect.
 */
class ProductFeedChannel
{
    const GOOGLE = 'google';
    const MICROSOFT = 'microsoft';
    const META = 'meta';
    const PINTEREST = 'pinterest';
    const TIKTOK = 'tiktok';

    const FORMAT_TSV = 'tsv';
    const FORMAT_CSV = 'csv';

    /**
     * Shipping written as country:region:service:price, or as a bare amount.
     */
    const SHIPPING_DETAILED = 'detailed';
    const SHIPPING_AMOUNT = 'amount';

    /**
     * Rules per platform, from each one's product data specification.
     *
     * - google: support.google.com/merchants/answer/7052112
     * - microsoft: learn.microsoft.com/advertising/msa-help/hlp_ba_conc_aboutbingmerchantcentercatalogfile
     * - meta: developers.facebook.com/docs/marketing-api/catalog/reference
     * - pinterest: help.pinterest.com/business/article/before-you-get-started-with-catalogs
     * - tiktok: ads.tiktok.com/help/article/catalog-product-parameters
     */
    const DEFINITIONS = [
        self::GOOGLE => [
            'format' => self::FORMAT_TSV,
            'id_column' => 'id',
            'category_column' => 'google_product_category',
            'category_levels' => null,
            'required' => [],
            'availability' => ['in stock' => 'in_stock', 'out of stock' => 'out_of_stock', 'preorder' => 'preorder'],
            'identifier_exists' => ['yes', 'no'],
            'sale_price_currency' => true,
            'shipping' => self::SHIPPING_DETAILED,
            'availability_date' => true,
            'max_description' => 5000,
            'compact_offsets' => true,
            'local_destinations' => 'Free_local_listings,Local_inventory_ads',
        ],
        self::MICROSOFT => [
            'format' => self::FORMAT_TSV,
            'id_column' => 'id',
            'category_column' => 'product_category',
            'category_levels' => null,
            'required' => [],
            'availability' => ['in stock' => 'in stock', 'out of stock' => 'out of stock', 'preorder' => 'preorder'],
            'identifier_exists' => ['TRUE', 'FALSE'],
            'sale_price_currency' => false,
            'shipping' => self::SHIPPING_AMOUNT,
            'availability_date' => false,
            'max_description' => 10000,
            'compact_offsets' => false,
            'local_destinations' => null,
        ],
        self::META => [
            'format' => self::FORMAT_TSV,
            'id_column' => 'id',
            'category_column' => 'google_product_category',
            'category_levels' => null,
            'required' => ['brand'],
            // Meta knows only these two values; a preorder is orderable.
            'availability' => ['in stock' => 'in stock', 'out of stock' => 'out of stock', 'preorder' => 'in stock'],
            'identifier_exists' => null,
            'sale_price_currency' => true,
            'shipping' => self::SHIPPING_DETAILED,
            'availability_date' => false,
            'max_description' => 9999,
            'compact_offsets' => false,
            'local_destinations' => null,
        ],
        self::PINTEREST => [
            'format' => self::FORMAT_TSV,
            'id_column' => 'id',
            'category_column' => 'google_product_category',
            'category_levels' => null,
            'required' => [],
            'availability' => ['in stock' => 'in stock', 'out of stock' => 'out of stock', 'preorder' => 'preorder'],
            'identifier_exists' => null,
            'sale_price_currency' => true,
            'shipping' => self::SHIPPING_DETAILED,
            'availability_date' => false,
            'max_description' => 10000,
            'compact_offsets' => false,
            'local_destinations' => null,
        ],
        // Scheduled TikTok feeds must be CSV. The category is a path of the
        // English taxonomy, three levels at most; the shipping syntax TikTok
        // documents is not the Google one, so shipping is left out.
        self::TIKTOK => [
            'format' => self::FORMAT_CSV,
            'id_column' => 'sku_id',
            'category_column' => 'google_product_category',
            'category_levels' => 3,
            'required' => ['brand'],
            'availability' => ['in stock' => 'in stock', 'out of stock' => 'out of stock', 'preorder' => 'preorder'],
            'identifier_exists' => null,
            'sale_price_currency' => true,
            'shipping' => null,
            'availability_date' => false,
            'max_description' => 5000,
            'compact_offsets' => false,
            'local_destinations' => null,
        ],
    ];

    /**
     * Column order. The two columns that always hold a value come last, so
     * a line never ends with a separator, which Microsoft rejects.
     */
    const COLUMNS = [
        'id', 'item_group_id', 'title', 'description', 'link', 'image_link', 'additional_image_link',
        'price', 'sale_price', 'sale_price_effective_date', 'brand', 'gtin', 'mpn', 'identifier_exists',
        '{category}', 'product_type', 'color', 'size', 'material', 'pattern', 'shipping',
        'excluded_destination', 'availability_date', 'availability', 'condition',
    ];

    /** Columns written even when every value is empty. */
    const REQUIRED = ['id', 'title', 'description', 'link', 'image_link', 'price', 'availability', 'condition'];

    /**
     * @return array<int, string>
     */
    public static function all()
    {
        return array_keys(self::DEFINITIONS);
    }

    /**
     * @param string $channel
     *
     * @return bool
     */
    public static function exists($channel)
    {
        return is_string($channel) && isset(self::DEFINITIONS[$channel]);
    }

    /**
     * @param string $channel
     *
     * @return string
     */
    public static function contentType($channel)
    {
        return self::DEFINITIONS[$channel]['format'] === self::FORMAT_CSV ? 'text/csv; charset=utf-8' : 'text/plain; charset=utf-8';
    }

    /**
     * @param string $channel
     *
     * @return string
     */
    public static function extension($channel)
    {
        return self::DEFINITIONS[$channel]['format'] === self::FORMAT_CSV ? 'csv' : 'txt';
    }

    /**
     * Writes neutral offers in the dialect of one platform.
     *
     * @param string $channel
     * @param array<int, array> $offers as produced by ProductFeedBuilder
     * @param string $currency ISO 4217 code
     * @param string $country ISO 3166 code of the shop, for shipping
     * @param ProductFeedTaxonomy $taxonomy turns category IDs into paths
     *
     * @return array [lines of the file, columns written]
     */
    public static function render($channel, array $offers, $currency, $country, ProductFeedTaxonomy $taxonomy)
    {
        $rules = self::DEFINITIONS[$channel];
        $rows = [];
        foreach ($offers as $offer) {
            $rows[] = self::row($rules, $offer, $currency, $country, $taxonomy);
        }

        $required = array_merge(self::REQUIRED, $rules['required']);
        $columns = [];
        foreach (self::COLUMNS as $column) {
            if ($column === '{category}') {
                $column = $rules['category_column'];
            }
            if ($column === 'identifier_exists' && $rules['identifier_exists'] === null) {
                continue;
            }
            if (in_array($column, $required, true)) {
                $columns[] = $column;
                continue;
            }
            foreach ($rows as $row) {
                if ($row[$column] !== '') {
                    $columns[] = $column;
                    break;
                }
            }
        }

        $header = [];
        foreach ($columns as $column) {
            $header[] = $column === 'id' ? $rules['id_column'] : $column;
        }

        $lines = [self::line($rules, $header)];
        foreach ($rows as $row) {
            $cells = [];
            foreach ($columns as $column) {
                $cells[] = $row[$column];
            }
            $lines[] = self::line($rules, $cells);
        }

        return [$lines, $header];
    }

    /**
     * Values never hold a tab or a line break (see ProductFeedText), so a
     * tab-separated line needs no quoting; a CSV one quotes what holds a
     * comma or a double quote.
     *
     * @param array $rules
     * @param array<int, string> $cells
     *
     * @return string
     */
    private static function line(array $rules, array $cells)
    {
        if ($rules['format'] === self::FORMAT_TSV) {
            return implode("\t", $cells);
        }

        foreach ($cells as $index => $cell) {
            if (strpbrk($cell, ",\"") !== false) {
                $cells[$index] = '"' . str_replace('"', '""', $cell) . '"';
            }
        }

        return implode(',', $cells);
    }

    /**
     * @param array $rules
     * @param array $offer
     * @param string $currency
     * @param string $country
     * @param ProductFeedTaxonomy $taxonomy
     *
     * @return array<string, string>
     */
    private static function row(array $rules, array $offer, $currency, $country, ProductFeedTaxonomy $taxonomy)
    {
        $salePrice = '';
        if ($offer['sale_price'] !== null) {
            $salePrice = ProductFeedText::amount($offer['sale_price']) . ($rules['sale_price_currency'] ? ' ' . $currency : '');
        }

        $shipping = '';
        if ($offer['shipping'] !== null && $rules['shipping'] !== null) {
            $amount = ProductFeedText::amount($offer['shipping']);
            $shipping = $rules['shipping'] === self::SHIPPING_DETAILED
                ? $country . ':::' . $amount . ' ' . $currency
                : $amount;
        }

        $availability = $rules['availability'][$offer['availability']];

        return [
            'id' => $offer['id'],
            'item_group_id' => $offer['item_group_id'],
            'title' => $offer['title'],
            'description' => ProductFeedText::truncate($offer['description'], $rules['max_description']),
            'link' => $offer['link'],
            'image_link' => $offer['image_link'],
            'additional_image_link' => $offer['additional_image_link'],
            'price' => ProductFeedText::amount($offer['price']) . ' ' . $currency,
            'sale_price' => $salePrice,
            'sale_price_effective_date' => self::dates($rules, $offer['sale_price_effective_date']),
            'brand' => $offer['brand'],
            'gtin' => $offer['gtin'],
            'mpn' => $offer['mpn'],
            'identifier_exists' => $rules['identifier_exists'] === null ? '' : $rules['identifier_exists'][$offer['identified'] ? 0 : 1],
            $rules['category_column'] => $rules['category_levels'] === null
                ? $offer['category']
                : $taxonomy->englishPath($offer['category'], $rules['category_levels']),
            'product_type' => $offer['product_type'],
            'color' => $offer['color'],
            'size' => $offer['size'],
            'material' => $offer['material'],
            'pattern' => $offer['pattern'],
            'shipping' => $shipping,
            'excluded_destination' => $offer['without_local'] && $rules['local_destinations'] !== null
                ? $rules['local_destinations']
                : '',
            'availability_date' => $rules['availability_date'] && $availability === 'preorder'
                ? self::dates($rules, $offer['availability_date'])
                : '',
            'availability' => $availability,
            'condition' => $offer['condition'],
        ];
    }

    /**
     * Google documents time zone offsets without a colon (+0200).
     *
     * @param array $rules
     * @param string $value
     *
     * @return string
     */
    private static function dates(array $rules, $value)
    {
        return $rules['compact_offsets'] ? preg_replace('/([+-]\d{2}):(\d{2})(?=\/|$)/', '$1$2', $value) : $value;
    }
}
