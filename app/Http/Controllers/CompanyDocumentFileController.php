<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Documents\CompanyDocument;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Files of «Documentación de la empresa»: only through here (session + CompanyDocumentPolicy::view), each view
 * (inline) or download (?descargar=1) logged. Without access it is a 404: the document's existence is not revealed.
 */
final class CompanyDocumentFileController
{
    public function __invoke(Request $request, int $companyDocument, int $index): Response
    {
        $user = $request->user();
        $document = CompanyDocument::query()->find($companyDocument);
        abort_unless($user instanceof User && $document !== null && Gate::forUser($user)->allows('view', $document), 404);
        $file = ((array) $document->files)[$index] ?? abort(404);
        abort_unless(Storage::disk(CompanyDocument::DISK)->exists($file['path']), 404);

        $download = $request->boolean('descargar');
        $document->logAccess($user, $index, $download ? 'descargar' : 'ver', $request->ip());

        return Storage::disk(CompanyDocument::DISK)->response($file['path'], $file['name'], [
            'Content-Type' => $file['mime'],
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex',
            'Cache-Control' => 'private, no-store',
        ], $download ? 'attachment' : 'inline');
    }
}
