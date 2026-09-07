<?php

/**
 * @author Artem <gammaironak@gmail.com>
 */
declare(strict_types=1);

namespace ImperaPayPalHl\Modules\PayPalGateway\Hooks\Actions;

use ImperaPayPalHl\Modules\PayPalGateway\WcGatewaySettings;

/**
 * An enabled gateway with no shared key hides itself at the checkout, which on
 * its own looks like the plugin is broken. This says why.
 *
 * Only for a gateway that is actually switched on: a plugin sitting installed
 * and disabled is not a misconfiguration, and a notice on every admin screen
 * teaches people to ignore notices.
 */
final class MissingKeyNotice
{
    public function __construct()
    {
        add_action('admin_notices', [$this, 'render']);
    }

    public function render(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        /** @var WcGatewaySettings $settings */
        $settings = ImperaPPHContainer()->get(WcGatewaySettings::class);

        if ($settings->get('enabled', 'no') !== 'yes' || $settings->getSharedKey() !== '') {
            return;
        }

        printf(
            '<div class="notice notice-error"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
            esc_html('Impera Gateway PayPal:'),
            esc_html('no proxy shared key is configured, so PayPal is hidden at the checkout. Set the same key the proxy uses.'),
            esc_url($this->settingsUrl()),
            esc_html('Open the gateway settings')
        );
    }

    private function settingsUrl(): string
    {
        return admin_url('admin.php?page=wc-settings&tab=checkout&section=' . IMPERA_PPH_GATEWAY_ID);
    }
}
