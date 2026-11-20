<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Acquisition\GhlHandoff;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * GoHighLevel → Liberu: "opportunity won" webhook from the acquisition pipeline's workflow. Authenticated with a
 * shared secret header (GHL workflow webhooks are not signed); the opportunity itself is then verified with the
 * GHL API before anything is created.
 */
final class GhlWebhookController
{
    public function won(Request $request): JsonResponse
    {
        $secret = (string) config('ghl.webhook_secret');
        if ($secret === '' || ! hash_equals($secret, (string) $request->header('X-Clarto-Webhook-Secret', ''))) {
            return response()->json(['message' => 'No autorizado.'], 401);
        }

        $id = (string) ($request->input('opportunity_id') ?? $request->input('opportunityId') ?? $request->input('opportunity.id')
            ?? $request->input('customData.opportunity_id') ?? $request->input('id') ?? '');
        if ($id === '' || ! preg_match('/^[A-Za-z0-9_-]{6,64}$/', $id)) {
            return response()->json(['message' => 'Falta el identificador de la oportunidad.'], 422);
        }

        try {
            $result = GhlHandoff::process($id);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'No se ha podido verificar o registrar la oportunidad; se reintentará.'], 503);
        }

        $h = $result['handoff'];

        return response()->json([
            'estado' => $result['status'],
            'motivo' => $result['reason'] ?? null,
            'expediente' => $h->instruction_id ? ['propietario' => $h->party_id, 'inmueble' => $h->property_id, 'encargo' => $h->instruction_id] : null,
        ]);
    }
}
