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
 * Turns shop data into values Microsoft Merchant Center accepts: plain text
 * on a single line, bounded lengths, valid barcodes, dotted decimals.
 */
class ProductFeedText
{
    /** Tags whose boundaries separate words once the markup is gone. */
    const BLOCK_TAGS = 'br|p|div|li|ul|ol|dl|dt|dd|h[1-6]|tr|td|th|table|thead|tbody|blockquote|section|article|header|footer|hr|pre';

    /** GTIN prefixes Microsoft rejects: in-store codes and coupons. */
    const RESTRICTED_GTIN_PREFIXES = ['2', '02', '04', '05', '981', '982', '983'];

    /**
     * HTML fragment to a single line of plain text.
     *
     * @param string $html
     * @param int $maxLength
     *
     * @return string
     */
    public static function plain($html, $maxLength)
    {
        $text = self::validUtf8((string) $html);
        $text = preg_replace('#<(script|style)\b[^>]*>.*?</\1\s*>#is', ' ', $text);
        $text = preg_replace('#</?(?:' . self::BLOCK_TAGS . ')\b[^>]*>#i', ' ', $text);
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Tabs and line breaks would break the file; non-breaking and
        // zero-width spaces only confuse the reviewers.
        $text = preg_replace('/[\x00-\x1F\x7F\x{00A0}\x{200B}-\x{200D}\x{2028}\x{2029}\x{FEFF}]+/u', ' ', $text);
        $text = trim(preg_replace('/\s+/u', ' ', $text));

        // A value wrapped in double quotes is read as a quoted field.
        if (mb_strlen($text) > 1 && mb_substr($text, 0, 1) === '"' && mb_substr($text, -1) === '"') {
            $text = trim(mb_substr($text, 1, -1));
        }

        return self::truncate($text, $maxLength);
    }

    /**
     * Cuts on a word boundary when one is close enough to the limit.
     *
     * @param string $text
     * @param int $maxLength
     *
     * @return string
     */
    public static function truncate($text, $maxLength)
    {
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        $cut = mb_substr($text, 0, $maxLength);
        $space = mb_strrpos($cut, ' ');
        if ($space !== false && $space >= $maxLength * 0.8) {
            $cut = mb_substr($cut, 0, $space);
        }

        return rtrim($cut, " ,;:-");
    }

    /**
     * A URL safe to drop in a tab-separated cell.
     *
     * @param string $url
     *
     * @return string
     */
    public static function url($url)
    {
        return str_replace([' ', "\t", "\r", "\n"], ['%20', '', '', ''], trim((string) $url));
    }

    /**
     * Two decimals with a dot, whatever the shop locale.
     *
     * @param float $amount
     *
     * @return string
     */
    public static function amount($amount)
    {
        return number_format((float) $amount, 2, '.', '');
    }

    /**
     * Returns the barcode when it is a GTIN Microsoft will accept, or an
     * empty string: wrong length, bad check digit, or a restricted range.
     *
     * @param string $code
     *
     * @return string
     */
    public static function gtin($code)
    {
        $code = preg_replace('/[\s-]+/', '', (string) $code);
        $length = strlen($code);

        if (!ctype_digit($code) || !in_array($length, [8, 12, 13, 14], true) || trim($code, '0') === '') {
            return '';
        }

        if (!self::hasValidCheckDigit($code)) {
            return '';
        }

        if ($length !== 8) {
            $gtin13 = $length === 14 ? substr($code, 1) : str_pad($code, 13, '0', STR_PAD_LEFT);
            if ($length !== 14 || $code[0] === '0') {
                foreach (self::RESTRICTED_GTIN_PREFIXES as $prefix) {
                    if (strpos($gtin13, $prefix) === 0) {
                        return '';
                    }
                }
            }
        }

        return $code;
    }

    /**
     * GS1 modulo 10: weights 3 and 1 alternate from the rightmost data digit.
     *
     * @param string $code
     *
     * @return bool
     */
    public static function hasValidCheckDigit($code)
    {
        $digits = array_map('intval', str_split($code));
        $check = array_pop($digits);
        $sum = 0;

        foreach (array_reverse($digits) as $position => $digit) {
            $sum += $digit * ($position % 2 === 0 ? 3 : 1);
        }

        return (10 - $sum % 10) % 10 === $check;
    }

    /**
     * Start/end of a sale in the ISO 8601 form Microsoft expects, or an
     * empty string when the sale has no end date.
     *
     * @param string $from Y-m-d H:i:s, zero date when unbounded
     * @param string $to Y-m-d H:i:s, zero date when unbounded
     * @param DateTimeZone $timezone
     *
     * @return string
     */
    public static function saleWindow($from, $to, DateTimeZone $timezone)
    {
        if (!self::isRealDate($to)) {
            return '';
        }

        $end = new DateTime($to, $timezone);
        $start = self::isRealDate($from) ? new DateTime($from, $timezone) : new DateTime('today', $timezone);

        if ($start >= $end) {
            return '';
        }

        return $start->format('Y-m-d\TH:iP') . '/' . $end->format('Y-m-d\TH:iP');
    }

    /**
     * @param string $date
     *
     * @return bool
     */
    private static function isRealDate($date)
    {
        return is_string($date) && $date !== '' && strpos($date, '0000-00-00') !== 0;
    }

    /**
     * Drops invalid byte sequences so the regular expressions never fail.
     *
     * @param string $text
     *
     * @return string
     */
    private static function validUtf8($text)
    {
        return mb_check_encoding($text, 'UTF-8') ? $text : mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    }
}
