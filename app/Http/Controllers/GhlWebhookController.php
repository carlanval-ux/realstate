<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Acquisition\GhlHandoff;
use App\Acquisition\GhlWhatsApp;
use App\Modules\ClartoModules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * GoHighLevel workflow webhooks → Liberu. GHL does not sign them: a shared secret authenticates them, in the
 * X-Clarto-Webhook-Secret header («Custom Webhook» action) or in the custom data as `clarto_secreto` (standard
 * «Webhook» action, L-6). Whatever the body says about the contact or opportunity is then verified with the GHL API.
 */
final class GhlWebhookController
{
    public function won(Request $request): JsonResponse
    {
        if (! self::authorised($request)) {
            return response()->json(['message' => 'No autorizado.'], 401);
        }

        // Module switched off in «Módulos»: no new file is opened (4xx, so GHL does not keep retrying).
        if (($refusal = ClartoModules::refusal(ClartoModules::CAPTACION)) !== null) {
            return response()->json(['message' => $refusal, 'modulo' => ClartoModules::moduleName(ClartoModules::CAPTACION), 'estado' => 'desactivado'], 403);
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

    /**
     * L-4: a GHL workflow asks for an appointment WhatsApp (A3, A4, A5). Custom data: tipo, texto (GHL variables already
     * resolved), clave (idempotency), caduca (ISO, optional). The contact comes from GHL's standard body.
     */
    public function whatsApp(Request $request): JsonResponse
    {
        if (! self::authorised($request)) {
            return response()->json(['message' => 'No autorizado.'], 401);
        }
        if (($refusal = ClartoModules::refusal(ClartoModules::WHATSAPP)) !== null) {
            return response()->json(['estado' => 'rechazado', 'motivo' => $refusal], 403);
        }
        $data = [
            'contact_id' => (string) ($request->input('contact_id') ?? $request->input('contactId') ?? $request->input('contact.id') ?? $request->input('customData.contact_id') ?? ''),
            'tipo' => (string) ($request->input('customData.tipo') ?? $request->input('tipo') ?? ''),
            'texto' => trim((string) ($request->input('customData.texto') ?? $request->input('texto') ?? '')),
            'clave' => (string) ($request->input('customData.clave') ?? $request->input('clave') ?? ''),
            'caduca' => $request->input('customData.caduca') ?? $request->input('caduca'),
        ];
        $validator = Validator::make($data, [
            'contact_id' => ['required', 'regex:/^[A-Za-z0-9_-]{6,64}$/'],
            'tipo' => ['required', Rule::in(GhlWhatsApp::TYPES)],
            'texto' => ['required', 'string', 'max:1000'],
            'clave' => ['required', 'string', 'max:191'],
            'caduca' => ['nullable', 'date'],
        ]);
        if ($validator->fails()) {
            return response()->json(['estado' => 'rechazado', 'motivo' => $validator->errors()->first()], 422);
        }

        try {
            $result = GhlWhatsApp::order($validator->validated() + ['caduca' => $data['caduca']]);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'No se ha podido comprobar el contacto en GHL; se reintentará.'], 503);
        }

        return response()->json(['estado' => $result['estado'], 'motivo' => $result['motivo']], $result['http']);
    }

    /**
     * L-18 (B.3 v2 §5.5): WF-03 v2 / WF-13 drive the WhatsApp nurture Liberu sends. Custom data: evento
     * (inicio|correo|parada), codigo (c2…c5), estado (enviado|sin_correo), rama (activa|templada|fria), asesor_nombre.
     */
    public function nurture(Request $request): JsonResponse
    {
        if (! self::authorised($request)) {
            return response()->json(['message' => 'No autorizado.'], 401);
        }
        $in = fn (string $k) => $request->input('customData.'.$k) ?? $request->input($k);
        $data = [
            'contact_id' => (string) ($request->input('contact_id') ?? $request->input('contactId') ?? $request->input('contact.id') ?? $in('contact_id') ?? ''),
            'evento' => (string) $in('evento'), 'codigo' => $in('codigo'), 'estado' => $in('estado'), 'rama' => $in('rama'), 'asesor_nombre' => $in('asesor_nombre'),
        ];
        $validator = Validator::make($data, [
            'contact_id' => ['required', 'regex:/^[A-Za-z0-9_-]{6,64}$/'],
            'evento' => ['required', Rule::in(['inicio', 'correo', 'parada'])],
            'rama' => ['required_if:evento,inicio', 'nullable', Rule::in(array_keys(\App\Acquisition\NurturePlan::SLOTS))],
            'codigo' => ['required_if:evento,correo', 'nullable', Rule::in(['c2', 'c3', 'c4', 'c5'])],
            'estado' => ['nullable', Rule::in(['enviado', 'sin_correo'])],
            'asesor_nombre' => ['nullable', 'string', 'max:80'],
        ]);
        if ($validator->fails()) {
            return response()->json(['estado' => 'rechazado', 'motivo' => $validator->errors()->first()], 422);
        }
        $result = \App\Acquisition\NurturePlan::event($data);

        return response()->json(array_diff_key($result, ['http' => true]), $result['http']);
    }

    private static function authorised(Request $request): bool
    {
        $secret = (string) config('ghl.webhook_secret');
        $given = (string) ($request->header('X-Clarto-Webhook-Secret') ?: $request->input('customData.clarto_secreto') ?: $request->input('clarto_secreto') ?: '');

        return $secret !== '' && hash_equals($secret, $given);
    }
}
