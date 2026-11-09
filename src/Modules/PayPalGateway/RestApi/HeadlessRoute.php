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

        $storefrontOrigin = $wcSettings->getStorefrontOrigin();

        // The bridge inside the frame accepts a handshake from this one origin
        // and no other, so a link built without it is a frame that can never be
        // told what the order is worth. Better a clear failure here than a
        // checkout that loads and then refuses every click.
        if ($storefrontOrigin === '') {
            $this->logger->error('HeadlessRoute | pp-link requested but the storefront origin is not configured.');

            return new WP_REST_Response([
                'code'    => 'impera_pph_no_storefront_origin',
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
            'HeadlessRoute | pp-link issued | client=%s proxy=%s storefront=%s',
            $client,
            $baseUrl,
            $storefrontOrigin
        ));

        $url = add_query_arg(
            array_filter([
                'client'   => $client,
                'checkout' => 'yes',
                'intent'   => 'capture',
                'currency' => get_woocommerce_currency(),
                // The shop, for the proxy's allow-list and its statistics.
                'mid'      => Utils::base64Encode(Utils::homeDomain()),
                // The storefront, which in a headless setup is a different
                // origin from the shop and is what the bridge is pinned to.
                'sfo'      => Utils::base64Encode($storefrontOrigin),
                'sandbox'  => $wcSettings->isSandbox() ? 'true' : null,
            ], static fn ($value) => $value !== null),
            $baseUrl . '/checkout'
        );

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
