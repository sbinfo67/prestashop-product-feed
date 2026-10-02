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
 * Turns a Google product category, given as an ID, into its English path.
 *
 * Most platforms take the ID as it is. TikTok wants the English path, three
 * levels at most, so the module keeps a copy of Google's published list,
 * refreshed once a month. Without it, TikTok simply gets no category.
 */
class ProductFeedTaxonomy
{
    const URL = 'https://www.google.com/basepages/producttype/taxonomy-with-ids.en-US.txt';

    const MAX_AGE = 2592000;

    /** After a failed download, wait this long before trying again. */
    const RETRY_AFTER = 86400;

    /** @var string */
    private $file;

    /** @var array<int, string>|null */
    private $paths;

    /**
     * @param string $directory writable folder, with a trailing slash
     */
    public function __construct($directory)
    {
        $this->file = $directory . 'taxonomy-en-US.txt';
    }

    /**
     * @param string $value category ID or path, as entered in the module
     * @param int $levels deepest level kept
     *
     * @return string empty when an ID cannot be resolved
     */
    public function englishPath($value, $levels)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        if (ctype_digit($value)) {
            $paths = $this->paths();
            if (!isset($paths[(int) $value])) {
                return '';
            }
            $value = $paths[(int) $value];
        }

        $parts = array_map('trim', explode('>', $value));

        return implode(' > ', array_slice($parts, 0, $levels));
    }

    /**
     * @return array<int, string>
     */
    private function paths()
    {
        if ($this->paths !== null) {
            return $this->paths;
        }

        $this->refreshIfNeeded();
        $this->paths = [];

        $content = @file_get_contents($this->file);
        foreach (explode("\n", $content === false ? '' : $content) as $line) {
            if (preg_match('/^(\d+) - (.+)$/', trim($line), $match)) {
                $this->paths[(int) $match[1]] = $match[2];
            }
        }

        return $this->paths;
    }

    private function refreshIfNeeded()
    {
        clearstatcache(true, $this->file);
        $age = is_file($this->file) ? time() - filemtime($this->file) : PHP_INT_MAX;
        if ($age < self::MAX_AGE) {
            return;
        }

        $failedAt = @file_get_contents($this->file . '.failed');
        if ($failedAt !== false && time() - (int) $failedAt < self::RETRY_AFTER) {
            return;
        }

        $content = Tools::file_get_contents(self::URL, false, null, 10);
        if (!is_string($content) || strpos($content, '# Google_Product_Taxonomy_Version') !== 0) {
            @file_put_contents($this->file . '.failed', (string) time());

            return;
        }

        $temporary = $this->file . '.' . getmypid() . '.tmp';
        if (@file_put_contents($temporary, $content) !== false && @rename($temporary, $this->file)) {
            @unlink($this->file . '.failed');
        } else {
            @unlink($temporary);
        }
    }
}
