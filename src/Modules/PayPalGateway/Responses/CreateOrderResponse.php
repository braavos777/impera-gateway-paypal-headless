<?php

/**
 * @author Artem <gammaironak@gmail.com>
 */
declare(strict_types=1);

namespace ImperaPayPalHl\Modules\PayPalGateway\Responses;

/**
 * The proxy's answer to "create a PayPal order for this WooCommerce order".
 */
final class CreateOrderResponse
{
    private function __construct(
        private bool $success,
        private ?string $ppOrderId,
        private ?string $redirectUrl,
        private ?string $approveUrl,
        private ?string $orderStatus,
        private string $mode,
        private ?string $errorCode,
        private ?string $errorMessage,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            success:      (bool) ($data['success'] ?? false),
            ppOrderId:    $data['pp_order_id'] ?? null,
            redirectUrl:  $data['redirect_url'] ?? null,
            approveUrl:   $data['approve_url'] ?? null,
            orderStatus:  $data['order_status'] ?? null,
            mode:         (string) ($data['mode'] ?? 'production'),
            errorCode:    $data['error_code'] ?? null,
            errorMessage: $data['error_message'] ?? null,
        );
    }

    public static function failed(string $message, string $code = 'request_failed'): self
    {
        return new self(false, null, null, null, null, 'production', $code, $message);
    }

    /**
     * A success with nowhere to send the customer is not a success: without the
     * redirect URL the checkout has nothing to return to WooCommerce.
     */
    public function isSuccess(): bool
    {
        return $this->success && !empty($this->redirectUrl);
    }

    public function getPpOrderId(): ?string
    {
        return $this->ppOrderId;
    }

    public function getRedirectUrl(): ?string
    {
        return $this->redirectUrl;
    }

    public function getApproveUrl(): ?string
    {
        return $this->approveUrl;
    }

    public function getOrderStatus(): ?string
    {
        return $this->orderStatus;
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function __toString(): string
    {
        return sprintf(
            'PayPal order created: PayPalOrderId=%s, OrderStatus=%s, Mode=%s',
            (string) $this->ppOrderId,
            (string) $this->orderStatus,
            $this->mode
        );
    }

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'pp_order_id' => $this->ppOrderId,
            'redirect_url' => $this->redirectUrl,
            'approve_url' => $this->approveUrl,
            'order_status' => $this->orderStatus,
            'mode' => $this->mode,
            'error_code' => $this->errorCode,
            'error_message' => $this->errorMessage,
        ];
    }
}
