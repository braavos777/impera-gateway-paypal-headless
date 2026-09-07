<?php

/**
 * @author Artem <gammaironak@gmail.com>
 */
declare(strict_types=1);

namespace ImperaPayPalHl\Core\Contract;

interface LoggerInterface
{

    public function log(string $level, mixed $message, array $context = []): void;

    public function debug(mixed $message, array $context = []): void;

    public function info(mixed $message, array $context = []): void;

    public function warn(mixed $message, array $context = []): void;

    public function error(mixed $message, array $context = []): void;

}
