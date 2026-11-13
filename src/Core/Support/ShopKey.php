<?php

/**
 * @author Artem <gammaironak@gmail.com>
 */
declare(strict_types=1);

namespace ImperaPayPalHl\Core\Support;

use WP_Error;
use WP_REST_Request;

/**
 * This shop's own key for the proxy: a key id and a secret, issued on the
 * proxy's "Shop keys" page for this shop and this gateway only.
 *
 * It replaces the key that used to be built into the plugin and shared by every
 * shop. Without it the gateway is not offered at the checkout - there is no
 * built-in fallback.
 *
 * Source: the wp-config constants IMPERA_PPH_KEY_ID / IMPERA_PPH_SECRET if
 * defined (a deployment decision the admin screen does not override),
 * otherwise the gateway settings fields shop_key_id / shop_key_secret.
 *
 * Signature protocol v1 - must match SignedRequest on the proxy byte for byte:
 * headers X-Key-Id, X-Timestamp, X-Request-Id, X-Signature, where the signature
 * is HMAC-SHA256 under the secret of
 *     "v1\n" METHOD "\n" rest_route "\n" timestamp "\n" request_id "\n" sha256_hex(body)
 * The body is encoded once and exactly those bytes are signed and sent.
 */
final class ShopKey
{
    public const VERSION = 'v1';

    public const MAX_SKEW = 300;

    private const REPLAY_PREFIX = 'impera_pph_rid_';

    public static function keyId(): string
    {
        if (defined('IMPERA_PPH_KEY_ID') && trim((string) constant('IMPERA_PPH_KEY_ID')) !== '') {
            return trim((string) constant('IMPERA_PPH_KEY_ID'));
        }

        return trim((string) (self::settings()['shop_key_id'] ?? ''));
    }

    public static function secret(): string
    {
        if (defined('IMPERA_PPH_SECRET') && trim((string) constant('IMPERA_PPH_SECRET')) !== '') {
            return trim((string) constant('IMPERA_PPH_SECRET'));
        }

        return trim((string) (self::settings()['shop_key_secret'] ?? ''));
    }

    public static function isConfigured(): bool
    {
        return preg_match('/^mk_[a-f0-9]{16}$/', self::keyId()) === 1 && strlen(self::secret()) >= 32;
    }

    /**
     * POSTs $payload to the proxy, signed. $url is the full proxy URL in the
     * "/?rest_route=/namespace/v1/path" form every caller uses.
     */
    public static function post(string $url, array $payload, int $timeout = 45): array|WP_Error
    {
        if (!self::isConfigured()) {
            return new WP_Error('impera_no_shop_key', 'The shop key for the proxy is not configured.');
        }

        $body = (string) wp_json_encode($payload);

        return wp_remote_post($url, [
            'sslverify' => true,
            'timeout'   => $timeout,
            'headers'   => self::headers('POST', self::routeOf($url), $body),
            'body'      => $body,
        ]);
    }

    /**
     * @return array<string, string>
     */
    public static function headers(string $method, string $route, string $body): array
    {
        $timestamp = (string) time();
        $requestId = wp_generate_uuid4();

        return [
            'Content-Type' => 'application/json',
            'X-Key-Id'     => self::keyId(),
            'X-Timestamp'  => $timestamp,
            'X-Request-Id' => $requestId,
            'X-Signature'  => self::sign($method, $route, $timestamp, $requestId, $body),
        ];
    }

    /**
     * Checks a push from the proxy: our own key id, a fresh timestamp, the
     * signature over the raw body, and a request id not seen before. Push
     * handlers are idempotent, so a transient is enough for the replay window.
     */
    public static function verify(WP_REST_Request $request): bool
    {
        if (!self::isConfigured()) {
            return false;
        }

        $keyId     = trim((string) $request->get_header('X-Key-Id'));
        $timestamp = trim((string) $request->get_header('X-Timestamp'));
        $requestId = trim((string) $request->get_header('X-Request-Id'));
        $signature = trim((string) $request->get_header('X-Signature'));

        if (
            !hash_equals(self::keyId(), $keyId)
            || preg_match('/^\d{9,11}$/', $timestamp) !== 1
            || abs(time() - (int) $timestamp) > self::MAX_SKEW
            || preg_match('/^[A-Za-z0-9-]{16,64}$/', $requestId) !== 1
            || $signature === ''
        ) {
            return false;
        }

        $expected = self::sign(
            $request->get_method(),
            $request->get_route(),
            $timestamp,
            $requestId,
            (string) $request->get_body()
        );

        if (!hash_equals($expected, $signature)) {
            return false;
        }

        $replayKey = self::REPLAY_PREFIX . md5($keyId . '|' . $requestId);

        if (get_transient($replayKey) !== false) {
            return false;
        }

        set_transient($replayKey, 1, 2 * self::MAX_SKEW + 60);

        return true;
    }

    public static function sign(string $method, string $route, string $timestamp, string $requestId, string $body): string
    {
        $payload = implode("\n", [
            self::VERSION,
            strtoupper($method),
            $route,
            $timestamp,
            $requestId,
            hash('sha256', $body),
        ]);

        return hash_hmac('sha256', $payload, self::secret());
    }

    /**
     * The REST route out of "https://proxy/?rest_route=/ns/v1/path".
     */
    public static function routeOf(string $url): string
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $params);

        if (is_string($params['rest_route'] ?? null)) {
            return $params['rest_route'];
        }

        // Pretty permalinks: /wp-json/ns/v1/path
        $path = (string) parse_url($url, PHP_URL_PATH);
        $pos  = strpos($path, '/wp-json/');

        return $pos === false ? $path : substr($path, $pos + strlen('/wp-json'));
    }

    /**
     * Shows why the gateway is missing at the checkout: an enabled gateway
     * without a shop key is hidden there.
     */
    public static function bootNotice(string $label): void
    {
        static $booted = false;

        if ($booted) {
            return;
        }

        $booted = true;

        add_action('admin_notices', static function () use ($label): void {
            if (!current_user_can('manage_woocommerce') || self::isConfigured()) {
                return;
            }

            if ((self::settings()['enabled'] ?? 'no') !== 'yes') {
                return;
            }

            printf(
                '<div class="notice notice-error"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
                esc_html($label . ':'),
                esc_html('no shop key for the proxy is configured, so the gateway is hidden at the checkout. Issue a key on the proxy ("Shop keys") and enter the key id and secret.'),
                esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=' . IMPERA_PPH_GATEWAY_ID)),
                esc_html('Open the gateway settings')
            );
        });
    }

    private static function settings(): array
    {
        $settings = get_option('woocommerce_' . IMPERA_PPH_GATEWAY_ID . '_settings', []);

        return is_array($settings) ? $settings : [];
    }
}
