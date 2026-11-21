<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\InboundMail\InboundMail;
use App\InboundMail\InboundMailAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/** Attachment of a message read from the company mailbox: staff of its team with «correo.contenido»; always downloaded. */
final class InboundMailAttachmentController
{
    public function __invoke(Request $request, InboundMail $mail, int $index): Response
    {
        abort_unless(InboundMailAccess::canReadContent($request->user(), $mail->team_id), 404);
        $file = ((array) $mail->attachments)[$index] ?? abort(404);
        abort_unless(filled($file['path'] ?? null) && Storage::disk(InboundMail::DISK)->exists($file['path']), 404);

        return Storage::disk(InboundMail::DISK)->download($file['path'], $file['name'], [
            'Content-Type' => 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
        ]);
    }
}
