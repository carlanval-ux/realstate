<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ImprovementRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/** Photos of a thread (or of one of its replies, ?respuesta={event}): private disk, only for whoever may view the thread. */
final class ImprovementAttachmentController
{
    public function __invoke(Request $request, ImprovementRequest $improvementRequest, int $index): Response
    {
        Gate::authorize('view', $improvementRequest);
        $attachments = $request->filled('respuesta')
            ? $improvementRequest->events()->whereKey($request->integer('respuesta'))->firstOrFail()->attachments
            : $improvementRequest->attachments;
        $file = ((array) $attachments)[$index] ?? abort(404);

        return Storage::disk(ImprovementRequest::DISK)->response($file['path'], $file['name'], [
            'Content-Type' => $file['mime'],
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=300',
        ], 'inline');
    }
}
