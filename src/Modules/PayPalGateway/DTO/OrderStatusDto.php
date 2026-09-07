<?php

/**
 * @author Artem <gammaironak@gmail.com>
 */
declare(strict_types=1);

namespace ImperaPayPalHl\Modules\PayPalGateway\DTO;

use ImperaPayPalHl\Core\Support\Utils;
use JsonSerializable;

final class OrderStatusDto implements JsonSerializable
{
    private function __construct(
        public readonly string $ppOrderId,
        public readonly int $wcOrderId,
        public readonly string $merchantSite,
        public readonly string $mode,
        /**
         * Whether an approved order should be collected as part of this call.
         * True when the customer has just come back from PayPal; false for a
         * plain read, so that a status poll can never move money.
         */
        public readonly bool $capture,
    ) {}

    public static function create(string $ppOrderId, int $wcOrderId, string $mode, bool $capture = true): self
    {
        return new self(
            ppOrderId:    $ppOrderId,
            wcOrderId:    $wcOrderId,
            merchantSite: Utils::homeDomain(),
            mode:         $mode,
            capture:      $capture,
        );
    }

    public function jsonSerialize(): array
    {
        return [
            'pp_order_id'   => $this->ppOrderId,
            'wc_order_id'   => $this->wcOrderId,
            'merchant_site' => $this->merchantSite,
            'mode'          => $this->mode,
            'capture'       => $this->capture,
        ];
    }
}
