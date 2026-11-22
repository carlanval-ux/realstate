<?php

declare(strict_types=1);

namespace App\Collaborators\Import;

/**
 * Reads a listing page's text when it carries no usable JSON-LD / OpenGraph data. Chosen with IMPORTACION_IA
 * (default: free OpenRouter models), so another provider plugs in without touching the crawler.
 */
interface ListingAi
{
    /**
     * @return array<string, mixed> any of: es_inmueble (bool), referencia, precio, operacion, tipo, m2, habitaciones, banos, municipio, zona
     *
     * @throws \App\Ai\AiQuotaExceeded rate limit: the crawler stops asking for the rest of the run
     * @throws \App\Ai\AiUnavailable anything else: that page keeps what the markup gave
     */
    public function extract(string $url, string $text): array;
}
