<?php

/**
 * @author Artem <gammaironak@gmail.com>
 */
declare(strict_types=1);

namespace ImperaPayPalHl\Modules\PayPalGateway\Exceptions;

use Exception;
use Throwable;

/**
 * A payment that could not be started, with a short marker appended to the
 * customer-facing message so a support ticket can name the step that failed
 * without the customer being shown the reason.
 */
final class PaymentProcessException extends Exception
{
    public function __construct(
        string $message,
        private readonly string $marker,
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getMarker(): string
    {
        return $this->marker;
    }

    public function getErrorMessage(): string
    {
        return sprintf('%s[%s]', $this->getMessage(), $this->marker);
    }
}
