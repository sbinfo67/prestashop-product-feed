<?php
/**
 * Microsoft Ads product feed for PrestaShop.
 *
 * @author    SBINFO <contact@sbinfo.pro>
 * @copyright 2026 SBINFO
 * @license   MIT https://opensource.org/licenses/MIT
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Settings of the module, read shop by shop.
 */
class MicrosoftAdsFeedConfig
{
    const OUT_OF_STOCK_INCLUDE = 'include';
    const OUT_OF_STOCK_EXCLUDE = 'exclude';

    const DESCRIPTION_LONG = 'long';
    const DESCRIPTION_SHORT = 'short';

    /** Fields a product attribute group can be exported as. */
    const VARIANT_FIELDS = ['color', 'size', 'material', 'pattern'];

    /** Shared by every shop: the secret part of the feed address. */
    const TOKEN_KEY = 'MSADSFEED_TOKEN';

    /** Stored values; an empty default is filled at install time from the shop. */
    const DEFAULTS = [
        'MSADSFEED_LANG' => '',
        'MSADSFEED_TAX_INCL' => '1',
        'MSADSFEED_COMBINATIONS' => '1',
        'MSADSFEED_OUT_OF_STOCK' => self::OUT_OF_STOCK_INCLUDE,
        'MSADSFEED_DESCRIPTION' => self::DESCRIPTION_LONG,
        'MSADSFEED_IMAGE_TYPE' => '',
        'MSADSFEED_DEFAULT_BRAND' => '',
        'MSADSFEED_MPN_FROM_REF' => '0',
        'MSADSFEED_SHIPPING_COST' => '',
        'MSADSFEED_FREE_SHIPPING_FROM' => '',
        'MSADSFEED_EXCLUDED_PRODUCTS' => '',
        'MSADSFEED_CATEGORY_MAP' => '{}',
        'MSADSFEED_CATEGORY_EXCLUDED' => '',
        'MSADSFEED_ATTRIBUTE_MAP' => '{}',
    ];

    /** @var int */
    private $idShop;

    /**
     * @param int $idShop
     */
    public function __construct($idShop)
    {
        $this->idShop = (int) $idShop;
    }

    /**
     * @param string $key
     *
     * @return string
     */
    public function get($key)
    {
        $value = Configuration::get($key, null, null, $this->idShop);

        return $value === false || $value === null ? self::DEFAULTS[$key] : (string) $value;
    }

    /**
     * @return int
     */
    public function languageId()
    {
        $idLang = (int) $this->get('MSADSFEED_LANG');
        if ($idLang > 0 && Language::getLanguage($idLang)) {
            return $idLang;
        }

        return (int) Configuration::get('PS_LANG_DEFAULT', null, null, $this->idShop);
    }

    /**
     * @return bool
     */
    public function taxIncluded()
    {
        return (bool) $this->get('MSADSFEED_TAX_INCL');
    }

    /**
     * @return bool
     */
    public function exportCombinations()
    {
        return (bool) $this->get('MSADSFEED_COMBINATIONS');
    }

    /**
     * @return bool
     */
    public function excludeOutOfStock()
    {
        return $this->get('MSADSFEED_OUT_OF_STOCK') === self::OUT_OF_STOCK_EXCLUDE;
    }

    /**
     * @return bool
     */
    public function preferShortDescription()
    {
        return $this->get('MSADSFEED_DESCRIPTION') === self::DESCRIPTION_SHORT;
    }

    /**
     * Image format name, or an empty string for the original upload.
     *
     * @return string
     */
    public function imageType()
    {
        return $this->get('MSADSFEED_IMAGE_TYPE');
    }

    /**
     * @return string
     */
    public function defaultBrand()
    {
        return trim($this->get('MSADSFEED_DEFAULT_BRAND'));
    }

    /**
     * @return bool
     */
    public function referenceAsMpn()
    {
        return (bool) $this->get('MSADSFEED_MPN_FROM_REF');
    }

    /**
     * Flat shipping cost, or null when the column should be left out.
     *
     * @return float|null
     */
    public function shippingCost()
    {
        return self::parseAmount($this->get('MSADSFEED_SHIPPING_COST'));
    }

    /**
     * Order amount from which shipping is free, or null.
     *
     * @return float|null
     */
    public function freeShippingFrom()
    {
        return self::parseAmount($this->get('MSADSFEED_FREE_SHIPPING_FROM'));
    }

    /**
     * @return array<int, true>
     */
    public function excludedProducts()
    {
        return array_fill_keys(self::parseIds($this->get('MSADSFEED_EXCLUDED_PRODUCTS')), true);
    }

    /**
     * Category ID to Microsoft product category (taxonomy ID or path).
     *
     * @return array<int, string>
     */
    public function categoryMap()
    {
        $map = [];
        foreach (self::decodeMap($this->get('MSADSFEED_CATEGORY_MAP')) as $idCategory => $value) {
            $value = trim((string) $value);
            if ((int) $idCategory > 0 && $value !== '') {
                $map[(int) $idCategory] = $value;
            }
        }

        return $map;
    }

    /**
     * @return array<int, true>
     */
    public function excludedCategories()
    {
        return array_fill_keys(self::parseIds($this->get('MSADSFEED_CATEGORY_EXCLUDED')), true);
    }

    /**
     * Attribute group ID to feed field (color, size, material, pattern).
     * Groups missing from the stored map fall back to color for colour
     * groups and size for the others, so a new group is never silently lost.
     *
     * @return array<int, string>
     */
    public function attributeMap()
    {
        $stored = self::decodeMap($this->get('MSADSFEED_ATTRIBUTE_MAP'));
        $map = [];

        foreach (AttributeGroup::getAttributesGroups($this->languageId()) as $group) {
            $idGroup = (int) $group['id_attribute_group'];
            if (array_key_exists($idGroup, $stored)) {
                $field = (string) $stored[$idGroup];
            } else {
                $field = $group['is_color_group'] ? 'color' : 'size';
            }
            $map[$idGroup] = in_array($field, self::VARIANT_FIELDS, true) ? $field : '';
        }

        return $map;
    }

    /**
     * @return string
     */
    public static function token()
    {
        $token = (string) Configuration::getGlobalValue(self::TOKEN_KEY);
        if ($token === '') {
            $token = self::renewToken();
        }

        return $token;
    }

    /**
     * @return string
     */
    public static function renewToken()
    {
        $token = bin2hex(random_bytes(16));
        Configuration::updateGlobalValue(self::TOKEN_KEY, $token);

        return $token;
    }

    /**
     * @param mixed $candidate
     *
     * @return bool
     */
    public static function isValidToken($candidate)
    {
        $token = (string) Configuration::getGlobalValue(self::TOKEN_KEY);

        return $token !== '' && is_string($candidate) && hash_equals($token, $candidate);
    }

    /**
     * Accepts "6,90", "6.90" or "6.9 €"; returns null for an empty field.
     *
     * @param string $value
     *
     * @return float|null
     */
    public static function parseAmount($value)
    {
        $value = str_replace([',', ' ', "\xC2\xA0", '€'], ['.', '', '', ''], trim((string) $value));
        if ($value === '' || !is_numeric($value) || (float) $value < 0) {
            return null;
        }

        return (float) $value;
    }

    /**
     * @param string $value
     *
     * @return array<int, int>
     */
    public static function parseIds($value)
    {
        $ids = [];
        foreach (preg_split('/[\s,;]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $id) {
            if (ctype_digit($id) && (int) $id > 0) {
                $ids[(int) $id] = (int) $id;
            }
        }

        return array_values($ids);
    }

    /**
     * @param string $json
     *
     * @return array
     */
    private static function decodeMap($json)
    {
        $decoded = json_decode((string) $json, true);

        return is_array($decoded) ? $decoded : [];
    }
}
