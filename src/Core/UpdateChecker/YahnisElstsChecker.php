<?php

/**
 * @author Artem <gammaironak@gmail.com>
 */
declare(strict_types=1);

namespace ImperaPayPalHl\Core\UpdateChecker;

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

class YahnisElstsChecker
{

    public function __construct()
    {
        add_action('plugins_loaded', [$this, 'checkUpdates']);
    }

    public function checkUpdates() : void
    {

        if ( ! defined( 'GITHUB_ACCESS_TOKEN' ) || empty( GITHUB_ACCESS_TOKEN ) ){
            return;
        }


        $updateChecker = PucFactory::buildUpdateChecker(
            'https://github.com/Dripheat/impera-gateway-paypal-headless/',
            IMPERA_PPH_PLUGIN_DIR . '/impera-gateway-paypal-headless.php',
            'impera-gateway-paypal-headless'
        );

        // Использовать GitHub Releases (не просто ветку)
        $updateChecker->getVcsApi()->enableReleaseAssets();

    }
}
