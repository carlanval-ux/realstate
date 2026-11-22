<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Collaborators\Collaborator;
use App\WhatsApp\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Liberu\Foundation\Organizations\Models\Team;

/**
 * WAHA ↔ HighLevel connector (separate service on the internal Docker network) → Liberu: «may this phone go to GHL?».
 * Owner's rule: collaborators and their messages never reach GoHighLevel. The connector asks before forwarding an
 * incoming WhatsApp and before sending a reply, and sends nothing when this endpoint fails (fail-closed).
 * Internal only (refused through the public proxy), shared key in X-Conector-Clave. Answers only a boolean.
 */
final class ConnectorExclusionController
{
    public function __invoke(Request $request): JsonResponse
    {
        $key = (string) config('whatsapp.connector_key');
        if ($key === '' || $request->headers->has('X-Forwarded-For') || $request->headers->has('X-Forwarded-Host')) {
            return response()->json(['message' => 'No disponible.'], 403);
        }
        abort_unless(hash_equals($key, (string) $request->header('X-Conector-Clave', '')), 401);

        $phone = Phone::e164((string) $request->input('telefono', ''));
        if ($phone === null) {
            return response()->json(['message' => 'Teléfono no válido.'], 422);
        }
        $teamId = Team::query()->oldest()->value('id');   // same team as the valuations API and GhlWhatsApp

        return response()->json(['excluido' => Collaborator::ownsAddress($teamId, $phone)]);
    }
}
