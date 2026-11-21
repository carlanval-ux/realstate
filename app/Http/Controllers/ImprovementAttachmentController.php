<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ImprovementRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/** Screenshots of an improvement request: private disk, only for whoever may view the request. */
final class ImprovementAttachmentController
{
    public function __invoke(ImprovementRequest $improvementRequest, int $index): Response
    {
        Gate::authorize('view', $improvementRequest);
        $file = ((array) $improvementRequest->attachments)[$index] ?? abort(404);

        return Storage::disk(ImprovementRequest::DISK)->response($file['path'], $file['name'], [
            'Content-Type' => $file['mime'],
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=300',
        ], 'inline');
    }
}
