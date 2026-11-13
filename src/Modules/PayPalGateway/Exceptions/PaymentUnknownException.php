<?php

/**
 * @author Artem <gammaironak@gmail.com>
 */
declare(strict_types=1);

namespace ImperaPayPalHl\Modules\PayPalGateway\Exceptions;

use RuntimeException;

/**
 * The proxy did not give a definite answer - no answer at all, a 5xx, a 502
 * outcome_unknown or a body that cannot be read - after a call in which PayPal
 * may have collected the money (a capture, or a status read that captures).
 *
 * Never a reason to fail the order: the buyer might pay twice. The order is
 * held and PaymentReconciler asks again.
 */
final class PaymentUnknownException extends RuntimeException
{
}
