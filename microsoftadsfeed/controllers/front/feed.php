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
 * The address Microsoft Merchant Center downloads. Serves the stored file,
 * after rebuilding it if the catalogue changed since the last build.
 */
class MicrosoftAdsFeedFeedModuleFrontController extends ModuleFrontController
{
    /** @var MicrosoftAdsFeed */
    public $module;

    /**
     * Microsoft keeps downloading while the shop is in maintenance.
     */
    protected function displayMaintenancePage()
    {
    }

    /**
     * Microsoft downloads from abroad; geolocation must not turn it away.
     */
    protected function displayRestrictedCountryPage()
    {
    }

    public function postProcess()
    {
        if (!MicrosoftAdsFeedConfig::isValidToken(Tools::getValue('token'))) {
            $this->respond(404, "Not found\n");
        }

        $idShop = (int) $this->context->shop->id;
        $path = $this->module->store()->feedPath($idShop);

        try {
            $this->module->ensureFeed($idShop);
        } catch (Throwable $e) {
            PrestaShopLogger::addLog('Microsoft Ads feed: ' . $e->getMessage(), 3, null, 'Module', (int) $this->module->id);
            // An older file is better than no file: Microsoft would
            // otherwise report every product as missing.
            if (!is_file($path)) {
                $this->respond(503, "Feed temporarily unavailable\n");
            }
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        clearstatcache(true, $path);
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: inline; filename="microsoft-ads-feed.txt"');
        header('Content-Length: ' . filesize($path));
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', filemtime($path)) . ' GMT');
        header('Cache-Control: no-store, max-age=0');
        header('X-Robots-Tag: noindex, nofollow');

        if (strtoupper((string) $_SERVER['REQUEST_METHOD']) !== 'HEAD') {
            readfile($path);
        }
        exit;
    }

    /**
     * @param int $status
     * @param string $body
     */
    private function respond($status, $body)
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store, max-age=0');
        header('X-Robots-Tag: noindex, nofollow');
        echo $body;
        exit;
    }
}
