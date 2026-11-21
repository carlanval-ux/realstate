<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Signing\Signing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * DocuSeal → CLARTO. X-Docuseal-Signature "<ts>.<hex>" = HMAC-SHA256 of "<ts>.<raw body>" with the webhook secret,
 * 300 s window. The event is only a notice: the state is reconciled with an authenticated GET (App\Signing\Signing).
 * 401 uniform; 200 for processed, duplicated, orphan or ignored; 503 so DocuSeal retries.
 */
final class DocusealWebhookController
{
    public function __invoke(Request $request): JsonResponse
    {
        if (strlen($request->getContent()) > 1024 * 1024) {
            return response()->json(['error' => 'demasiado_grande'], 413);
        }
        [$status, $result] = Signing::handleDocusealWebhook($request->getContent(), $request->header('X-Docuseal-Signature'));

        return response()->json(['resultado' => $result], $status);
    }
}
