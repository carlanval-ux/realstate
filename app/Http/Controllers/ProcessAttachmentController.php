<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Processes\Models\ProcessAttachment;
use App\Processes\ProcessAccess;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/** Attachment of a process version: private disk, 404 for anyone who may not view that version (no existence leak). */
final class ProcessAttachmentController
{
    public function __invoke(int $attachment): Response
    {
        $file = ProcessAttachment::query()->find($attachment);
        if ($file === null || ! ProcessAccess::canDownload(auth()->user(), $file) || ! Storage::disk($file->disk)->exists($file->path)) {
            abort(404);
        }

        return Storage::disk($file->disk)->response($file->path, $file->original_name, [
            'Content-Type' => $file->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=300',
        ], in_array($file->mime, ProcessAttachment::INLINE, true) ? 'inline' : 'attachment');
    }
}
