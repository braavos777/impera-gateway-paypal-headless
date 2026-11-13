<?php

/**
 * @author Artem <gammaironak@gmail.com>
 * @date 11.09.2026
 */
declare(strict_types=1);

namespace ImperaPayPalHl\Modules\PayPalGateway\RestApi;

use ImperaPayPalHl\Core\Contract\LoggerInterface;
use ImperaPayPalHl\Core\RestApi\Action;
use ImperaPayPalHl\Core\RestApi\Contract\AbstractRoute;
use ImperaPayPalHl\Core\Support\ShopKey;
use ImperaPayPalHl\Core\Support\StringUtil;
use ImperaPayPalHl\Core\Support\Utils;
use ImperaPayPalHl\Modules\PayPalGateway\WcGatewaySettings;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Hands a headless storefront the proxy page it embeds as the PayPal iframe.
 *
 * Public on purpose: the URL carries nothing secret - the proxy domain ends up
 * in the iframe's src anyway, and the session is a fresh random string. What it
 * does carry is the context the proxy needs to serve that page safely, and
 * every part of it is decided here rather than by the browser that loads it.
 *
 * The shop's domain is not in it: the shop is named by Utils::siteToken(), an
 * HMAC under the shared key that the proxy resolves against its allowed list.
 * The domain exists only between this server and the proxy.
 */
class HeadlessRoute extends AbstractRoute
{

    public const BASE_URL = '/paypal';

    /**
     * Length of the per-checkout token. The proxy's RenderHeadlessButtons will
     * not render the page unless it is exactly this long.
     */
    private const CLIENT_TOKEN_LENGTH = 30;

    private LoggerInterface $logger;

    public function __construct()
    {
        $this->logger = ImperaPPHContainer()->get(LoggerInterface::class);
    }

    public function defineActions(): array
    {
        return [
            Action::post(self::BASE_URL . '/pp-link', [$this, 'getPayPalIframeLink']),
        ];
    }


    public function getPayPalIframeLink(WP_REST_Request $request): WP_REST_Response
    {
        /**
         * @var WcGatewaySettings $wcSettings
         */
        $wcSettings = ImperaPPHContainer()->get(WcGatewaySettings::class);

        $proxyDomain = $wcSettings->getProxyDomain();

        // Without a domain buildDomain() yields a bare "https://", which the
        // storefront would happily load as a blank iframe.
        if ($proxyDomain === '') {
            $this->logger->error('HeadlessRoute | pp-link requested but the proxy domain is not configured.');

            return new WP_REST_Response([
                'code'    => 'impera_pph_no_proxy_domain',
                'message' => 'PayPal is not available right now.',
            ], 503);
        }

        // The session is opened by a call signed with this shop's own key;
        // without the key there is nothing to open it with.
        if (!ShopKey::isConfigured()) {
            $this->logger->error('HeadlessRoute | pp-link requested but the shop key is not configured.');

            return new WP_REST_Response([
                'code'    => 'impera_pph_no_shared_key',
                'message' => 'PayPal is not available right now.',
            ], 503);
        }

        $baseUrl = Utils::buildDomain($proxyDomain);

        // Only gets the page rendered. The proxy issues a session of its own
        // behind it, and that is what actually carries the shop, the currency
        // and the environment.
        $client = StringUtil::generateRandomString(self::CLIENT_TOKEN_LENGTH);

        // The proxy logs the same client value when the frame is opened
        // ("PayPal Frame | link requested"), so the two logs line up.
        $this->logger->info(sprintf(
            'HeadlessRoute | pp-link issued | client=%s proxy=%s',
            $client,
            $baseUrl
        ));

        $currency = get_woocommerce_currency();

        // One-time checkout session, opened server to server: the proxy records
        // which shop (this key's), which environment (this stored setting) and
        // which currency the page is for, and answers with a random token. The
        // link carries that token only - nothing in it names the shop or lets
        // the browser pick sandbox.
        $answer = ShopKey::post($baseUrl . '/?rest_route=/impera-proxy-payment/v1/paypal-redirect/checkout-session', [
            'mode'          => $wcSettings->getMode(),
            'currency'      => $currency,
            'checkout_ref'  => $client,
            'merchant_site' => Utils::homeDomain(),
        ], 15);

        $token = '';

        if (!is_wp_error($answer) && (int) wp_remote_retrieve_response_code($answer) === 200) {
            $token = (string) (Utils::getResponseJson($answer)['token'] ?? '');
        }

        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            $this->logger->error(sprintf(
                'HeadlessRoute | the proxy did not open a checkout session (%s)',
                is_wp_error($answer) ? $answer->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code($answer)
            ));

            return new WP_REST_Response([
                'code'    => 'impera_pph_no_session',
                'message' => 'PayPal is not available right now.',
            ], 503);
        }

        $url = add_query_arg([
            'client'   => $client,
            'checkout' => 'yes',
            'intent'   => 'capture',
            'currency' => $currency,
            'session'  => $token,
        ], $baseUrl . '/checkout');

        return new WP_REST_Response([
            'url' => $url,
            // The storefront needs this to open its half of the bridge. Parsing
            // it back out of the URL in the browser would be a guess where this
            // is a statement.
            'origin' => $baseUrl,
        ], 200);
    }


    public function permissionCallback(WP_REST_Request $request): bool
    {
        return true;
    }

    /**
     * A headless storefront has no logged-in WP user and so no `wp_rest`
     * nonce to present. Without this override AbstractRoute would answer
     * every call with a 403.
     */
    public function globalPermissionMiddleware(): array
    {
        return [];
    }
}
