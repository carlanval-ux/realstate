<?php

declare(strict_types=1);

namespace App\Portals\Pisos;

/** Outcome of one call to pisos.com, normalised. */
final class PisosResult
{
    /**
     * @param  'ok'|'rejected'|'unconfirmed'|'error'  $outcome
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $outcome,
        public readonly ?string $id = null,
        public readonly ?int $estado = null,
        public readonly ?string $message = null,
        public readonly ?string $errorCode = null,
        public readonly int $httpStatus = 0,
        public readonly array $raw = [],
    ) {}

    public function ok(): bool
    {
        return $this->outcome === 'ok';
    }
}
