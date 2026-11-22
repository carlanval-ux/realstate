<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Collaborators\CollaboratorAccess;
use App\SearchRequests\SearchRequest;
use App\SearchRequests\SearchRequestAccess;
use App\SearchRequests\SearchRequestResponse;
use App\SearchRequests\SearchRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

final class SearchRequestController
{
    /**
     * Inbound e-mail reply (JSON from the replies mailbox forwarder): from, subject, text, message_id, in_reply_to,
     * references, auto_submitted, content_type. Answers what happened with it; never 5xx for a reply it ignores.
     */
    public function inboundMail(Request $request): JsonResponse
    {
        $key = (string) config('solicitudes.inbound_mail_key');
        if ($key === '') {
            return response()->json(['error' => 'not_configured'], 503);
        }
        abort_unless(hash_equals($key, (string) $request->header('X-Solicitudes-Clave')), 401);
        if (($refusal = SearchRequests::refusal()) !== null) {
            return response()->json(['error' => $refusal], 403);
        }
        $data = $request->validate([
            'from' => ['required', 'string', 'max:320'], 'subject' => ['nullable', 'string', 'max:1000'], 'text' => ['nullable', 'string', 'max:200000'],
            'message_id' => ['nullable', 'string', 'max:500'], 'in_reply_to' => ['nullable', 'string', 'max:2000'], 'references' => ['nullable', 'string', 'max:20000'],
            'auto_submitted' => ['nullable', 'string', 'max:100'], 'content_type' => ['nullable', 'string', 'max:200'],
        ]);

        return response()->json(['result' => SearchRequests::inboundMail($data)]);
    }

    /** Request attachment ($response = 0) or proposal photo, for staff of the team or the collaborator who may see it. */
    public function file(Request $request, SearchRequest $searchRequest, int $response, int $index): Response
    {
        $user = $request->user();
        $staff = SearchRequestAccess::isStaff($user, $searchRequest->team_id);
        abort_unless($staff || CollaboratorAccess::canSee($user, $searchRequest), 404);
        if ($response === 0) {
            $file = ((array) $searchRequest->attachments)[$index] ?? abort(404);
        } else {
            $proposal = SearchRequestResponse::query()->where('search_request_id', $searchRequest->getKey())->findOrFail($response);
            abort_unless($staff || CollaboratorAccess::canSee($user, $proposal), 404);
            $file = ((array) $proposal->photos)[$index] ?? abort(404);
        }
        $path = is_array($file) ? ($file['path'] ?? '') : (string) $file;
        abort_unless($path !== '' && Storage::disk(SearchRequest::DISK)->exists($path), 404);

        return Storage::disk(SearchRequest::DISK)->response($path, is_array($file) ? ($file['name'] ?? basename($path)) : basename($path), [
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, max-age=300',
        ], 'inline');
    }
}
