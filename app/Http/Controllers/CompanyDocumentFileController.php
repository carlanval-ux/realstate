<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Documents\CompanyDocument;
use App\Documents\CompanyDocumentPreview;
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

    /** The viewer's frame for an expediente's file. */
    public function preview(Request $request, CompanyDocumentPreview $previews, int $companyDocument, int $index): Response
    {
        $document = $this->document($request, $companyDocument);

        return $this->servePreview($request, $previews, $document, ((array) $document->files)[$index] ?? abort(404));
    }

    /** The viewer's frame for an annotation's attachment. */
    public function annotationPreview(Request $request, CompanyDocumentPreview $previews, int $companyDocument, int $annotation): Response
    {
        $document = $this->document($request, $companyDocument);

        return $this->servePreview($request, $previews, $document, $document->annotations()->whereKey($annotation)->first()->file ?? abort(404));
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

    /**
     * Inline, for the viewer: a PDF or an image as it is; a Word / Excel / OpenDocument only as its converted PDF (the
     * original never inline). Framable by the panel only. No preview (no LibreOffice, a failed conversion): 404, and the
     * viewer page shows «Vista previa no disponible». Logged as «ha visto».
     *
     * @param  array{path: string, name: string, mime: string}  $file
     */
    private function servePreview(Request $request, CompanyDocumentPreview $previews, CompanyDocument $document, array $file): Response
    {
        $disk = Storage::disk(CompanyDocument::DISK);
        abort_unless($disk->exists($file['path']), 404);
        $user = $request->user();
        abort_unless($user instanceof User, 404);

        [$path, $mime, $name] = match (CompanyDocumentPreview::kind($file['mime'])) {
            'pdf', 'image' => [$file['path'], $file['mime'], $file['name']],
            'office' => [$previews->pdfFor($document, $file) ?? abort(404), 'application/pdf', pathinfo($file['name'], PATHINFO_FILENAME).'.pdf'],
            default => abort(404),
        };
        $document->logAccess($user, $file['name'], 'ver', $request->ip());

        return $disk->response($path, $name, [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex',
            'Cache-Control' => 'private, no-store',
            // The panel sends DENY everywhere (SecurityHeaders keeps a header already set): the viewer frames this.
            'X-Frame-Options' => 'SAMEORIGIN',
            'Content-Security-Policy' => "frame-ancestors 'self'",
        ], 'inline');
    }
}
