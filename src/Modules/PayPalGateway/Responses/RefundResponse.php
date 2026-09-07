<?php

/**
 * @author Artem <gammaironak@gmail.com>
 */
declare(strict_types=1);

namespace ImperaPayPalHl\Modules\PayPalGateway\Responses;

final class RefundResponse
{
    private function __construct(
        private bool $success,
        private string $refundStatus,
        private ?string $refundId,
        private ?string $refundedTotal,
        private ?string $currency,
        private ?string $errorMessage,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            success:       (bool) ($data['success'] ?? false),
            refundStatus:  (string) ($data['refund_status'] ?? 'FAILED'),
            refundId:      $data['refund_id'] ?? null,
            // PayPal's own running total for the capture, not a sum kept here -
            // adding refunds up locally double-counts one made in the dashboard.
            refundedTotal: $data['refunded_total'] ?? null,
            currency:      $data['currency'] ?? null,
            errorMessage:  $data['error_message'] ?? null,
        );
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getRefundId(): ?string
    {
        return $this->refundId;
    }

    public function getRefundStatus(): string
    {
        return $this->refundStatus;
    }

    public function getRefundedTotal(): ?string
    {
        return $this->refundedTotal;
    }

    public function getCurrency(): ?string
    {
        return $this->currency;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function toString(): string
    {
        if ($this->success) {
            return sprintf(
                'PayPal refund complete (RefundId=%s, RefundStatus=%s, RefundedTotal=%s %s)',
                (string) $this->refundId,
                $this->refundStatus,
                (string) $this->refundedTotal,
                (string) $this->currency
            );
        }

        return sprintf(
            'Failed refund by PayPal (RefundStatus=%s, ErrorMessage=%s)',
            $this->refundStatus,
            (string) $this->errorMessage
        );
    }
}
