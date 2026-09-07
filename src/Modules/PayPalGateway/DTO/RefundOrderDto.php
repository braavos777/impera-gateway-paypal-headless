<?php

/**
 * @author Artem <gammaironak@gmail.com>
 */
declare(strict_types=1);

namespace ImperaPayPalHl\Modules\PayPalGateway\DTO;

use InvalidArgumentException;
use JsonSerializable;

final class RefundOrderDto implements JsonSerializable
{
    private function __construct(
        public readonly string $captureId,
        public readonly string $ppOrderId,
        public readonly int $wcOrderId,
        public readonly string $merchantSite,
        public readonly mixed $amount,
        public readonly string $currency,
        public readonly string $mode,
        public readonly string $reason = 'Without reason',
    ) {}

    public static function fromArray(array $data): self
    {
        self::assertRequiredKeys($data);

        return new self(
            captureId:    (string) $data['capture_id'],
            ppOrderId:    (string) ($data['pp_order_id'] ?? ''),
            wcOrderId:    (int) $data['wc_order_id'],
            merchantSite: (string) $data['merchant_site'],
            amount:       $data['amount'],
            currency:     (string) $data['currency'],
            mode:         (string) $data['mode'],
            reason:       empty($data['reason']) ? 'Without reason' : (string) $data['reason'],
        );
    }

    private static function assertRequiredKeys(array $data): void
    {
        // The capture is what PayPal credits - there is no endpoint that refunds
        // an order - so a payment without one cannot be refunded at all.
        $required = ['capture_id', 'wc_order_id', 'merchant_site', 'amount', 'currency', 'mode'];

        foreach ($required as $key) {
            if (!array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
                throw new InvalidArgumentException(
                    sprintf('RefundOrderDto: missing or empty required key "%s".', $key)
                );
            }
        }
    }

    public function jsonSerialize(): array
    {
        return [
            'capture_id'    => $this->captureId,
            'pp_order_id'   => $this->ppOrderId,
            'wc_order_id'   => $this->wcOrderId,
            'merchant_site' => $this->merchantSite,
            'amount'        => $this->amount,
            'currency'      => $this->currency,
            'mode'          => $this->mode,
            'reason'        => $this->reason,
        ];
    }
}
