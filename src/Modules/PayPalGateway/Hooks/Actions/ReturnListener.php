<?php

/**
 * @author Artem <gammaironak@gmail.com>
 */
declare(strict_types=1);

namespace ImperaPayPalHl\Modules\PayPalGateway\Hooks\Actions;

use ImperaPayPalHl\Modules\PayPalGateway\UseCases\ReturnProcessor;

/**
 * Catches the customer landing back on the shop after PayPal. The proxy is what
 * redirects them here, onto the shop's home URL carrying a flag - so the flow
 * needs no page of its own and works on any theme.
 */
final class ReturnListener
{
    public function __construct()
    {
        add_action('template_redirect', [$this, 'listen'], 5);
    }

    public function listen(): void
    {
        if (is_admin()) {
            return;
        }

        $isReturn = isset($_GET[IMPERA_PPH_RETURN_FLAG]);
        $isCancel = isset($_GET[IMPERA_PPH_CANCEL_FLAG]);

        if (!$isReturn && !$isCancel) {
            return;
        }

        /** @var ReturnProcessor $returnProcessor */
        $returnProcessor = ImperaPPHContainer()->get(ReturnProcessor::class);

        if ($isReturn) {

            ImperaPPHLogger()->debug('ReturnListener: Return from PayPal');

            $returnProcessor->handleReturn(wp_unslash($_GET));

            return;
        }

        $returnProcessor->handleCancel(wp_unslash($_GET));
    }
}
