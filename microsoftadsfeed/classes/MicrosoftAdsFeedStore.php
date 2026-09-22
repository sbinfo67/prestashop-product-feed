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
 * Keeps the generated file of each shop, knows when it is out of date, and
 * makes sure two requests never write it at the same time.
 *
 * Every catalogue change stamps a marker file. A feed built after the last
 * stamp is fresh; one built before it is rebuilt at the next occasion. The
 * marker lives on disk rather than in the configuration table so that a
 * request always reads the latest value, not the copy cached at bootstrap.
 */
class MicrosoftAdsFeedStore
{
    /**
     * Beyond this age the feed is rebuilt even without a recorded change:
     * a special price can start or end on a date without anyone saving.
     */
    const MAX_AGE = 3600;

    /** @var string */
    private $directory;

    /**
     * @param string $directory writable folder, with a trailing slash
     */
    public function __construct($directory)
    {
        $this->directory = $directory;
    }

    /**
     * @return string
     */
    public function directory()
    {
        return $this->directory;
    }

    /**
     * @return bool
     */
    public function isWritable()
    {
        return is_dir($this->directory) && is_writable($this->directory);
    }

    /**
     * @param int $idShop
     *
     * @return string
     */
    public function feedPath($idShop)
    {
        // The token digest keeps the name unguessable on servers that serve
        // module folders without reading their .htaccess.
        return $this->directory . 'feed-' . (int) $idShop . '-' . substr(sha1(MicrosoftAdsFeedConfig::token()), 0, 12) . '.txt';
    }

    /**
     * Records that the catalogue changed.
     */
    public function markChanged()
    {
        @file_put_contents($this->directory . 'changed', sprintf('%.6F', microtime(true)), LOCK_EX);
    }

    /**
     * @return float
     */
    public function lastChange()
    {
        $value = @file_get_contents($this->directory . 'changed');

        return $value === false ? 0.0 : (float) $value;
    }

    /**
     * Summary of the last build, or null before the first one.
     *
     * @param int $idShop
     *
     * @return array|null
     */
    public function meta($idShop)
    {
        $json = @file_get_contents($this->metaPath($idShop));
        $meta = $json === false ? null : json_decode($json, true);

        return is_array($meta) ? $meta : null;
    }

    /**
     * @param int $idShop
     *
     * @return bool
     */
    public function isStale($idShop)
    {
        $meta = $this->meta($idShop);

        return $meta === null
            || !is_file($this->feedPath($idShop))
            || (float) $meta['started_at'] < $this->lastChange()
            || time() - (int) $meta['finished_at'] > self::MAX_AGE;
    }

    /**
     * Rebuilds the feed unless it is already fresh.
     *
     * @param int $idShop
     * @param callable $builder receives the shop ID, returns [lines, meta]
     *
     * @return array meta of the file now on disk
     */
    public function ensureFresh($idShop, callable $builder)
    {
        return $this->withLock(function () use ($idShop, $builder) {
            // Another request may have rebuilt it while this one waited.
            if (!$this->isStale($idShop)) {
                return $this->meta($idShop);
            }

            return $this->write($idShop, $builder);
        });
    }

    /**
     * Rebuilds the feed unless a build started after $changesUntil, in which
     * case that build already saw every change this request made.
     *
     * @param int $idShop
     * @param callable $builder
     * @param float|null $changesUntil
     *
     * @return array meta of the file now on disk
     */
    public function rebuild($idShop, callable $builder, $changesUntil = null)
    {
        return $this->withLock(function () use ($idShop, $builder, $changesUntil) {
            $meta = $this->meta($idShop);
            if ($changesUntil !== null && $meta !== null && is_file($this->feedPath($idShop))
                && (float) $meta['started_at'] > $changesUntil
            ) {
                return $meta;
            }

            return $this->write($idShop, $builder);
        });
    }

    /**
     * Deletes every generated file, before uninstalling.
     */
    public function purge()
    {
        foreach (glob($this->directory . 'feed-*') ?: [] as $file) {
            @unlink($file);
        }
        @unlink($this->directory . 'changed');
        @unlink($this->directory . 'build.lock');
    }

    /**
     * Deletes feed files named after a previous token, and temporary files
     * left by an interrupted build. Runs under the lock only, so it never
     * removes the temporary file of a build in progress.
     */
    private function purgeObsolete()
    {
        foreach (glob($this->directory . 'feed-*') ?: [] as $file) {
            if (substr($file, -5) === '.json') {
                continue;
            }
            if (!preg_match('/^feed-(\d+)-/', basename($file), $match) || $file !== $this->feedPath((int) $match[1])) {
                @unlink($file);
            }
        }
    }

    /**
     * @param int $idShop
     * @param callable $builder
     *
     * @return array
     */
    private function write($idShop, callable $builder)
    {
        $startedAt = microtime(true);
        list($lines, $meta) = $builder($idShop);

        $target = $this->feedPath($idShop);
        $temporary = $target . '.' . getmypid() . '.tmp';

        if (@file_put_contents($temporary, implode("\n", $lines) . "\n") === false || !@rename($temporary, $target)) {
            @unlink($temporary);
            throw new PrestaShopException('The feed file could not be written in ' . $this->directory);
        }

        $meta['started_at'] = $startedAt;
        $meta['finished_at'] = time();
        $meta['size'] = filesize($target);
        @file_put_contents($this->metaPath($idShop), json_encode($meta), LOCK_EX);

        $this->purgeObsolete();

        return $meta;
    }

    /**
     * @param callable $callback
     *
     * @return mixed
     */
    private function withLock(callable $callback)
    {
        $handle = @fopen($this->directory . 'build.lock', 'c');
        if ($handle === false) {
            throw new PrestaShopException('The feed folder is not writable: ' . $this->directory);
        }

        try {
            flock($handle, LOCK_EX);

            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @param int $idShop
     *
     * @return string
     */
    private function metaPath($idShop)
    {
        return $this->directory . 'feed-' . (int) $idShop . '.json';
    }
}
