<?php

declare(strict_types=1);

namespace App\Modules;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A new operation was attempted while its CLARTO module is switched off. HTTP 403 with a message the user can act
 * on; api/* renders it as JSON. Nothing that already exists is touched.
 */
final class ModuleDisabled extends HttpException
{
    public function __construct(public readonly string $module, string $message)
    {
        parent::__construct(403, $message);
    }
}
