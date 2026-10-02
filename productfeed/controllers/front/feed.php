<?php
/**
 * Product feeds for PrestaShop: Google, Microsoft, Meta, Pinterest.
 *
 * @author    SBINFO <contact@sbinfo.pro>
 * @copyright 2026 SBINFO
 * @license   MIT https://opensource.org/licenses/MIT
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The address each platform downloads. Serves the stored file of that
 * platform, after rebuilding the files if the catalogue changed since.
 */
class ProductFeedFeedModuleFrontController extends ModuleFrontController
{
    /** @var ProductFeed */
    public $module;

    /**
     * The platforms keep downloading while the shop is in maintenance.
     */
    protected function displayMaintenancePage()
    {
    }

    /**
     * The platforms download from abroad; geolocation must not turn them away.
     */
    protected function displayRestrictedCountryPage()
    {
    }

    public function postProcess()
    {
        // The address of the Microsoft-only module that preceded this one
        // carries no platform: it keeps serving the Microsoft dialect.
        $channel = (string) Tools::getValue('channel', ProductFeedChannel::MICROSOFT);
        if (!ProductFeedConfig::isValidToken(Tools::getValue('token')) || !ProductFeedChannel::exists($channel)) {
            $this->respond(404, "Not found\n");
        }

        $idShop = (int) $this->context->shop->id;
        $path = $this->module->store()->feedPath($idShop, $channel);

        try {
            $this->module->ensureFeed($idShop);
        } catch (Throwable $e) {
            PrestaShopLogger::addLog('Product feed: ' . $e->getMessage(), 3, null, 'Module', (int) $this->module->id);
            // An older file is better than no file: the platform would
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
        header('Content-Disposition: inline; filename="products-' . $channel . '.txt"');
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
