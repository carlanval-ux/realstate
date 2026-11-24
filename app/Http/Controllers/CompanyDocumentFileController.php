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
 * Files of «Documentación de la empresa» (the expediente's files and its annotations' attachments): only through here
 * (session + CompanyDocumentPolicy::view), each view (inline: PDF and images only) or download (?descargar=1, and
 * always for Word / Excel / OpenDocument) logged. Without access it is
 * a 404: the expediente's existence is not revealed.
 */
final class CompanyDocumentFileController
{
    public function __invoke(Request $request, int $companyDocument, int $index): Response
    {
        $document = $this->document($request, $companyDocument);

        return $this->serve($request, $document, ((array) $document->files)[$index] ?? abort(404));
    }

    public function annotation(Request $request, int $companyDocument, int $annotation): Response
    {
        $document = $this->document($request, $companyDocument);

        return $this->serve($request, $document, $document->annotations()->whereKey($annotation)->first()->file ?? abort(404));
    }

    private function document(Request $request, int $id): CompanyDocument
    {
        $user = $request->user();
        $document = CompanyDocument::query()->find($id);
        abort_unless($user instanceof User && $document !== null && Gate::forUser($user)->allows('view', $document), 404);

        return $document;
    }

    /** @param array{path: string, name: string, mime: string} $file */
    private function serve(Request $request, CompanyDocument $document, array $file): Response
    {
        abort_unless(Storage::disk(CompanyDocument::DISK)->exists($file['path']), 404);
        $user = $request->user();
        abort_unless($user instanceof User, 404);

        // Word, Excel and OpenDocument are never shown in the browser: always a download, and logged as one.
        $download = $request->boolean('descargar') || ! CompanyDocument::opensInBrowser($file['mime']);
        $document->logAccess($user, $file['name'], $download ? 'descargar' : 'ver', $request->ip());

        return Storage::disk(CompanyDocument::DISK)->response($file['path'], $file['name'], [
            'Content-Type' => $file['mime'],
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex',
            'Cache-Control' => 'private, no-store',
        ], $download ? 'attachment' : 'inline');
    }
}
