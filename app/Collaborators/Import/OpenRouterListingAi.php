<?php

declare(strict_types=1);

namespace App\Collaborators\Import;

use App\InboundMail\OpenRouterMailClassifier;

/** Free OpenRouter models only (reuses the inbound-mail client: free model, then OpenRouter's free router, never paid). */
final class OpenRouterListingAi implements ListingAi
{
    private const SYSTEM = <<<'TXT'
        Extraes datos de un anuncio inmobiliario español a partir del texto de su página web.
        Responde SOLO con un objeto JSON con estas claves (null si no aparece; no inventes nada):
        {"es_inmueble": true|false, "referencia": string, "precio": number, "operacion": "venta"|"alquiler"|"alquiler-temporal",
         "tipo": "piso"|"casa"|"chalet"|"adosado"|"atico"|"local"|"oficina"|"terreno"|"garaje"|"edificio"|"otro",
         "m2": number, "habitaciones": integer, "banos": integer, "municipio": string, "zona": string}
        es_inmueble = false si la página no es la ficha de UN inmueble concreto (listado, contacto, blog…).
        Nunca incluyas nombres, teléfonos, correos ni direcciones exactas de personas.
        TXT;

    public function extract(string $url, string $text): array
    {
        $reply = app(OpenRouterMailClassifier::class)->complete(self::SYSTEM, "URL: {$url}\n\n".$text, config('importacion.ia_modelo') ?: null);
        $json = preg_match('/\{.*\}/s', $reply['content'], $m) ? json_decode($m[0], true) : null;

        return is_array($json) ? $json : [];
    }
}
