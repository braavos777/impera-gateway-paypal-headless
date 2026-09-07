<?php

declare(strict_types=1);


function ImperaPPHMain()
{
    return ImperaPayPalHl\Core\ImperaPPHMain::getInstance();
}

function ImperaPPHContainer()
{
    return ImperaPPHMain()->container();
}

function ImperaPPHLogger(): ImperaPayPalHl\Core\Contract\LoggerInterface
{
    return ImperaPPHContainer()->get(ImperaPayPalHl\Core\Contract\LoggerInterface::class);
}
