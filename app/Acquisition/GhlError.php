<?php

declare(strict_types=1);

namespace App\Acquisition;

use Illuminate\Http\Client\Response;
use RuntimeException;

/** A non-2xx GHL answer, with the response (e.g. 400 OPPORTUNITY_NO_DUPLICATE carries meta.existingId). */
final class GhlError extends RuntimeException
{
    public function __construct(string $message, public readonly Response $response)
    {
        parent::__construct($message);
    }
}
