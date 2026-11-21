<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Albaranes\Albaran;
use App\Albaranes\AlbaranPdf;
use App\Documents\RecordDocument;
use App\Filament\Resources\FormTemplateResource;
use App\Signing\SignatureDocument;
use App\Signing\SignatureRequest;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Downloads of record files (session + team membership + the source's own permission). Another team's file, or a
 * restricted category without its permission, is a 404 (its existence is not revealed).
 */
final class DocumentDownloadController
{
    private const HEADERS = ['X-Content-Type-Options' => 'nosniff', 'X-Robots-Tag' => 'noindex', 'Cache-Control' => 'private, no-store'];

    public function __invoke(string $source, int $id): Response
    {
        $user = auth()->user();

        return match ($source) {
            'archivo' => $this->record($user, RecordDocument::query()->findOrFail($id)),
            'firma' => $this->signed($user, SignatureDocument::query()->with('request')->findOrFail($id)),
            'firma-enviado' => $this->sent($user, SignatureRequest::query()->findOrFail($id)),
            'albaran' => $this->albaran($user, Albaran::query()->findOrFail($id)),
            default => abort(404),
        };
    }

    private function record(?object $user, RecordDocument $document): Response
    {
        abort_unless($document->visibleTo($user), 404);
        abort_unless(Storage::disk($document->disk)->exists($document->path), 404);

        return Storage::disk($document->disk)->response($document->path, $document->original_name ?: basename($document->path), ['Content-Type' => $document->mime, ...self::HEADERS], 'attachment');
    }

    private function signed(?object $user, SignatureDocument $document): Response
    {
        abort_unless($document->request && RecordDocument::inTeam($user, $document->request->team_id) && FormTemplateResource::staff(), 404);

        return Storage::disk(config('firma.disk'))->response($document->path, $document->kind.'-'.$document->signature_request_id.'.pdf', ['Content-Type' => 'application/pdf', ...self::HEADERS], 'attachment');
    }

    private function sent(?object $user, SignatureRequest $request): Response
    {
        abort_unless(filled($request->document_path) && RecordDocument::inTeam($user, $request->team_id) && FormTemplateResource::staff(), 404);

        return Storage::disk(config('firma.disk'))->response($request->document_path, 'enviado-'.$request->getKey().'.pdf', ['Content-Type' => 'application/pdf', ...self::HEADERS], 'attachment');
    }

    private function albaran(?object $user, Albaran $albaran): Response
    {
        abort_unless(RecordDocument::inTeam($user, $albaran->team_id), 404);

        return response(AlbaranPdf::render($albaran->loadMissing(['lines', 'property'])), 200, [
            'Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$albaran->number.'.pdf"', ...self::HEADERS,
        ]);
    }
}
