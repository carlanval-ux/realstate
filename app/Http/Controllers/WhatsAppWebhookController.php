<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\WhatsApp\WhatsApp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * WAHA → panel events (incoming messages, delivery receipts, session status). WAHA calls liberu-app directly on
 * the internal network: anything that came through the public proxy is refused, and the body must carry WAHA's
 * HMAC SHA-512 signature with the shared key. Retries and duplicates are no-ops (App\WhatsApp\WhatsApp::handleEvent).
 */
final class WhatsAppWebhookController
{
    public function __invoke(Request $request): JsonResponse
    {
        $key = (string) config('whatsapp.webhook_hmac_key');
        if ($key === '' || $request->headers->has('X-Forwarded-For') || $request->headers->has('X-Forwarded-Host')) {
            return response()->json(['message' => 'No disponible.'], 403);
        }

        $signature = strtolower((string) $request->header('X-Webhook-Hmac', ''));
        if (! hash_equals(hash_hmac('sha512', $request->getContent(), $key), $signature)) {
            return response()->json(['message' => 'Firma no válida.'], 401);
        }

        $event = json_decode($request->getContent(), true);
        if (! is_array($event)) {
            return response()->json(['message' => 'Cuerpo no válido.'], 422);
        }

        try {
            $result = WhatsApp::handleEvent($event, $request->header('X-Webhook-Request-Id'));
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'No se ha podido registrar; se reintentará.'], 503);
        }

        return response()->json(['estado' => $result]);
    }
}
