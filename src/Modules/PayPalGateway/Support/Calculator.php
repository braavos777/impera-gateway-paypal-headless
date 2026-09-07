<?php

/**
 * @author Artem <gammaironak@gmail.com>
 */
declare(strict_types=1);

namespace ImperaPayPalHl\Modules\PayPalGateway\Support;

/**
 * Amounts travel to and from the proxy as decimal strings, but comparing two of
 * them as floats invites a cent of rounding noise to decide whether an order
 * gets paid - so every comparison happens in the smallest currency unit.
 *
 * PayPal supports exactly three zero-decimal currencies and no three-decimal
 * ones at all. The Stripe table must never be copied over: 5.000 KWD, which
 * Stripe takes happily, earns a 422 DECIMAL_PRECISION here.
 */
final class Calculator
{
    private const ZERO_DECIMAL_CURRENCIES = ['HUF', 'JPY', 'TWD'];

    public static function decimals(string $currency): int
    {
        return in_array(strtoupper(trim($currency)), self::ZERO_DECIMAL_CURRENCIES, true) ? 0 : 2;
    }

    public static function toMinor(string|float|int $value, string $currency): int
    {
        $normalized = str_replace(',', '.', trim((string) $value));
        $normalized = preg_replace('/\s+/u', '', $normalized);

        if (!is_numeric($normalized)) {
            return 0;
        }

        return max(0, (int) round((float) $normalized * (10 ** self::decimals($currency))));
    }

    public static function fromMinor(int $minor, string $currency): string
    {
        $decimals = self::decimals($currency);

        return number_format(abs($minor) / (10 ** $decimals), $decimals, '.', '');
    }

    public static function equals(string|float|int $left, string|float|int $right, string $currency): bool
    {
        return self::toMinor($left, $currency) === self::toMinor($right, $currency);
    }
}
