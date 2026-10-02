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
 * Turns the unit price of a product, as PrestaShop stores it, into the two
 * values Google and Microsoft expect: the quantity sold (250g) and the
 * quantity the price is shown for (1kg). The platforms compute the price per
 * kilo themselves from the price and these two values.
 *
 * PrestaShop keeps a unit price and a free-text unit ("le kg", "100 g"). The
 * quantity sold is the ratio between the price and the unit price, the same
 * ratio the product page uses to show the price per unit.
 */
class ProductFeedUnit
{
    /** What may come before the unit: "le kg", "par litre", "€ / 100 g". */
    const PREFIX = '(?:[\/€]|(?:par|pour|le|la|les|au|aux|per|the|a|an)(?=[\s\d])|l[\'’])\s*';

    /** Spellings accepted for each unit Google and Microsoft both know. */
    const UNITS = [
        'mg' => ['mg', 'milligramme', 'milligram'],
        'g' => ['g', 'gr', 'grs', 'gramme', 'gram'],
        'kg' => ['kg', 'kgs', 'kilo', 'kilogramme', 'kilogram'],
        'ml' => ['ml', 'millilitre', 'milliliter'],
        'cl' => ['cl', 'centilitre', 'centiliter'],
        'l' => ['l', 'lt', 'litre', 'liter'],
        'cm' => ['cm', 'centimètre', 'centimetre', 'centimeter'],
        'm' => ['m', 'mètre', 'metre', 'meter'],
        'sqm' => ['m2', 'm²', 'sqm', 'mètre carré', 'metre carre', 'square meter'],
        'ct' => ['ct', 'u', 'unité', 'unite', 'unit', 'pièce', 'piece', 'pc', 'pcs', 'pce', 'item',
            'gélule', 'gelule', 'capsule', 'comprimé', 'comprime', 'tablet', 'sachet'],
    ];

    /** Smaller unit used to write a quantity below one: 0.25kg is 250g. */
    const SMALLER = ['kg' => ['g', 1000], 'l' => ['ml', 1000], 'g' => ['mg', 1000], 'm' => ['cm', 100]];

    /** Larger unit tried when a base quantity is not one Google accepts. */
    const LARGER = ['mg' => ['g', 1000], 'g' => ['kg', 1000], 'ml' => ['l', 1000], 'cl' => ['l', 100], 'cm' => ['m', 100]];

    /** Base quantities Google accepts, besides 1, 2, 4, 8, 10 and 100. */
    const EXTRA_BASES = ['75cl', '750ml', '50kg', '1000kg'];

    /**
     * Reads a PrestaShop unit such as "le kg", "100 g" or "par litre".
     *
     * @param string $unity
     *
     * @return array|null [amount, unit], null when the unit is not recognised
     */
    public static function parse($unity)
    {
        $text = mb_strtolower(trim(ProductFeedText::plain((string) $unity, 100)), 'UTF-8');
        $text = preg_replace('/^(?:' . self::PREFIX . ')+/u', '', $text);
        $text = rtrim($text, " .\t");

        if (!preg_match('/^(\d+(?:[.,]\d+)?)?\s*(.+)$/u', $text, $match)) {
            return null;
        }

        $amount = $match[1] === '' ? 1.0 : (float) str_replace(',', '.', $match[1]);
        if ($amount <= 0) {
            return null;
        }

        // Plurals: grammes, litres, unités, pièces, gélules, kilos.
        $word = preg_replace('/(?<=[a-zé])s$/u', '', trim($match[2]));
        foreach (self::UNITS as $unit => $spellings) {
            if (in_array($word, $spellings, true) || in_array(trim($match[2]), $spellings, true)) {
                return [$amount, $unit];
            }
        }

        return null;
    }

    /**
     * @param float $price price of the product or combination, tax excluded, before discounts
     * @param float $unitPrice unit price, tax excluded
     * @param string $unity unit as entered in PrestaShop
     *
     * @return array|null ['measure' => '250g', 'base' => '1kg' or '', 'exact' => bool],
     *                    null when there is no unit price or the unit is not recognised
     */
    public static function measures($price, $unitPrice, $unity)
    {
        $parsed = self::parse($unity);
        if ($parsed === null || $unitPrice <= 0 || $price <= 0) {
            return null;
        }
        list($baseAmount, $unit) = $parsed;

        $amount = $price / $unitPrice * $baseAmount;
        $measureUnit = $unit;
        if ($amount < 1 && isset(self::SMALLER[$unit])) {
            list($measureUnit, $factor) = self::SMALLER[$unit];
            $amount *= $factor;
        }

        // Both prices are entered to the cent, so the ratio misses the
        // quantity sold by at most that rounding. Further away, the price
        // most likely changed and the unit price did not follow.
        $tolerance = 0.005 / $unitPrice + 0.005 / $price + 0.000001;
        $decimals = $measureUnit === 'ct' || $amount >= 100 ? 0 : ($amount >= 10 ? 1 : 2);
        $round = round($amount, $decimals);
        $exact = abs($amount - $round) <= $tolerance * $amount;

        return [
            'measure' => self::number($exact ? $round : $amount) . $measureUnit,
            'base' => self::base($baseAmount, $unit),
            'exact' => $exact,
        ];
    }

    /**
     * @param float $amount
     * @param string $unit
     *
     * @return string empty when Google would refuse it
     */
    private static function base($amount, $unit)
    {
        for ($guard = 0; $guard < 3; ++$guard) {
            $value = self::number($amount) . $unit;
            if (in_array(round($amount, 6), [1.0, 2.0, 4.0, 8.0, 10.0, 100.0], true) || in_array($value, self::EXTRA_BASES, true)) {
                return $value;
            }
            if (!isset(self::LARGER[$unit])) {
                break;
            }
            list($unit, $factor) = self::LARGER[$unit];
            $amount /= $factor;
        }

        return '';
    }

    /**
     * At most two decimals, dotted, without trailing zeros.
     *
     * @param float $value
     *
     * @return string
     */
    private static function number($value)
    {
        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    }
}
