<?php

/**
 * @author Artem <gammaironak@gmail.com>
 */
declare(strict_types=1);

namespace ImperaPayPalHl\Modules\PayPalGateway\Responses;

use ImperaPayPalHl\Modules\PayPalGateway\Support\Calculator;

/**
 * State of a PayPal order as reported by the proxy. The only source the gateway
 * trusts when settling an order - a browser coming back proves nothing.
 *
 * The same shape arrives by both routes: the status call the shop makes when the
 * customer returns, and the push the proxy sends when its webhook fires.
 */
final class PaymentResponse
{
    public const STATUS_CREATED            = 'created';
    public const STATUS_APPROVED           = 'approved';
    public const STATUS_ACTION_REQUIRED    = 'action_required';
    public const STATUS_PENDING            = 'pending';
    public const STATUS_PAID               = 'paid';
    public const STATUS_FAILED             = 'failed';
    public const STATUS_CANCELLED          = 'cancelled';
    public const STATUS_PARTIALLY_REFUNDED = 'partially_refunded';
    public const STATUS_REFUNDED           = 'refunded';
    public const STATUS_AMOUNT_MISMATCH    = 'amount_mismatch';
    public const STATUS_UNPAID             = 'unpaid';

    private function __construct(
        private bool $success,
        private string $ppOrderId,
        private mixed $wcOrderId,
        private ?string $orderInvoice,
        private ?string $captureId,
        private ?string $orderStatus,
        private ?string $captureStatus,
        private string $paymentStatus,
        private ?string $paymentSource,
        private string $currency,
        private int $grossAmountMinor,
        private int $paypalFeeMinor,
        private int $netAmountMinor,
        private int $refundedAmountMinor,
        private ?string $createTime,
        private string $mode,
        private ?string $approveUrl,
        private ?string $errorMessage,
        private array $payerDetails,
        private array $shippingDetails,
    ) {}

    public static function fromArray(array $data): self
    {
        $currency = strtoupper((string) ($data['currency'] ?? get_woocommerce_currency()));

        return new self(
            success:             (bool) ($data['success'] ?? false),
            ppOrderId:           (string) ($data['pp_order_id'] ?? ''),
            wcOrderId:           $data['wc_order_id'] ?? null,
            orderInvoice:        $data['order_invoice'] ?? null,
            // capture_id and transaction_id carry the same value; either name is accepted.
            captureId:           $data['capture_id'] ?? $data['transaction_id'] ?? null,
            orderStatus:         $data['order_status'] ?? null,
            captureStatus:       $data['capture_status'] ?? null,
            paymentStatus:       (string) ($data['payment_status'] ?? self::STATUS_UNPAID),
            paymentSource:       $data['payment_source'] ?? null,
            currency:            $currency,
            grossAmountMinor:    Calculator::toMinor($data['gross_amount'] ?? 0, $currency),
            paypalFeeMinor:      Calculator::toMinor($data['paypal_fee'] ?? 0, $currency),
            netAmountMinor:      Calculator::toMinor($data['net_amount'] ?? 0, $currency),
            refundedAmountMinor: Calculator::toMinor($data['refunded_amount'] ?? 0, $currency),
            createTime:          $data['create_time'] ?? null,
            mode:                (string) ($data['mode'] ?? 'production'),
            approveUrl:          $data['approve_url'] ?? null,
            errorMessage:        $data['error_message'] ?? null,
            payerDetails:        is_array($data['payer_details'] ?? null) ? $data['payer_details'] : [],
            shippingDetails:     is_array($data['shipping_details'] ?? null) ? $data['shipping_details'] : [],
        );
    }

    public static function failed(string $ppOrderId, mixed $wcOrderId, string $message): self
    {
        return new self(
            false, $ppOrderId, $wcOrderId, null, null, null, null, self::STATUS_FAILED, null,
            (string) get_woocommerce_currency(), 0, 0, 0, 0, null, 'production', null, $message, [], []
        );
    }

    /**
     * True only when PayPal reports the money as actually collected.
     */
    public function isPaid(): bool
    {
        return $this->paymentStatus === self::STATUS_PAID;
    }

    /**
     * PayPal has the money but has not cleared it yet - an eCheck, or a review.
     * Real, but not yet spendable, so the order goes on hold rather than through.
     */
    public function isPending(): bool
    {
        return $this->paymentStatus === self::STATUS_PENDING;
    }

    /**
     * Not a failure: PayPal is asking the buyer to pick another funding source
     * inside the same order, which stays perfectly payable.
     */
    public function needsPayerAction(): bool
    {
        return $this->paymentStatus === self::STATUS_ACTION_REQUIRED;
    }

    public function isCancelled(): bool
    {
        return $this->paymentStatus === self::STATUS_CANCELLED;
    }

    public function isFailed(): bool
    {
        return $this->paymentStatus === self::STATUS_FAILED;
    }

    public function isRefunded(): bool
    {
        return in_array($this->paymentStatus, [self::STATUS_REFUNDED, self::STATUS_PARTIALLY_REFUNDED], true);
    }

    /**
     * PayPal collected something other than what the order was for. Never
     * settled - only a person can decide what to do about it.
     */
    public function isAmountMismatch(): bool
    {
        return $this->paymentStatus === self::STATUS_AMOUNT_MISMATCH;
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getPpOrderId(): string
    {
        return $this->ppOrderId;
    }

    public function getWcOrderId(): mixed
    {
        return $this->wcOrderId;
    }

    public function getOrderInvoice(): ?string
    {
        return $this->orderInvoice;
    }

    public function getCaptureId(): ?string
    {
        return $this->captureId;
    }

    public function getOrderStatus(): ?string
    {
        return $this->orderStatus;
    }

    public function getCaptureStatus(): ?string
    {
        return $this->captureStatus;
    }

    public function getPaymentStatus(): string
    {
        return $this->paymentStatus;
    }

    public function getPaymentSource(): ?string
    {
        return $this->paymentSource;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    public function getApproveUrl(): ?string
    {
        return $this->approveUrl;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function getCreateTime(): ?string
    {
        return $this->createTime;
    }

    public function getPayerDetails(): array
    {
        return $this->payerDetails;
    }

    public function getShippingDetails(): array
    {
        return $this->shippingDetails;
    }

    public function grossAmountMinor(): int
    {
        return $this->grossAmountMinor;
    }

    public function refundedAmountMinor(): int
    {
        return $this->refundedAmountMinor;
    }

    public function grossAmount(): string
    {
        return Calculator::fromMinor($this->grossAmountMinor, $this->currency);
    }

    public function paypalFee(): string
    {
        return Calculator::fromMinor($this->paypalFeeMinor, $this->currency);
    }

    public function netAmount(): string
    {
        return Calculator::fromMinor($this->netAmountMinor, $this->currency);
    }

    public function refundedAmount(): string
    {
        return Calculator::fromMinor($this->refundedAmountMinor, $this->currency);
    }

    public function __toString(): string
    {
        return sprintf(
            'PayPalOrderId=%s, CaptureId=%s, OrderStatus=%s, CaptureStatus=%s, PaymentStatus=%s, Source=%s, Amount=%s %s, Fee=%s, Net=%s, Refunded=%s, CreateTime=%s',
            $this->ppOrderId,
            (string) $this->captureId,
            (string) $this->orderStatus,
            (string) $this->captureStatus,
            $this->paymentStatus,
            (string) $this->paymentSource,
            $this->grossAmount(),
            $this->currency,
            $this->paypalFee(),
            $this->netAmount(),
            $this->refundedAmount(),
            (string) $this->createTime
        );
    }
}
