<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\SearchRequests\SearchRequest;
use App\SearchRequests\SearchRequestLink;
use App\SearchRequests\SearchRequestResponse;
use App\SearchRequests\SearchRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Private answer link of a collaborator (https://panel…/s/{codigo}, no account needed):
 *  GET  /s/{codigo}                 the complete request, their own answers, and the answer form while it is open
 *  POST /s/{codigo}                 an answer or proposal (or a new version of an earlier one), photos/documents
 *  GET  /s/{codigo}/adjunto/{n}     an attachment of the request
 * Access is the link alone, limited to that request and that collaborator's own answers; it stops working when the
 * request is closed, covered or expired, when the recipient is withdrawn or when the team revokes it. GET never
 * changes the recipient's status (opening is not answering); previews and scanners are not even counted as opens.
 * Same headers as the public valuation report links: noindex, no-referrer, strict CSP, nothing from third parties.
 */
final class SearchRequestLinkController
{
    public function show(Request $request, string $codigo): Response
    {
        [$link, $refusal] = $this->resolve($codigo);
        if ($refusal !== null) {
            return $refusal;
        }
        if (! ValoracionesController::isPreview($request)) {
            $link->forceFill(['opens' => $link->opens + 1, 'last_opened_at' => now()])->saveQuietly();
        }

        return $this->page($link, $codigo, $request->boolean('enviada') ? 'enviada' : null);
    }

    public function store(Request $request, string $codigo): Response
    {
        [$link, $refusal] = $this->resolve($codigo);
        if ($refusal !== null) {
            return $refusal;
        }
        $recipient = $link->recipient;
        $validator = Validator::make($request->all(), [
            'kind' => ['required', 'in:'.implode(',', SearchRequestResponse::KINDS)],
            'parent_id' => ['nullable', 'integer'],
            'description' => ['nullable', 'string', 'max:5000', 'required_if:kind,aclaraciones'],
            'location' => ['nullable', 'string', 'max:250'],
            'price' => ['nullable', 'integer', 'min:0', 'max:999999999'],
            'features' => ['nullable', 'string', 'max:2000'],
            'links' => ['nullable', 'string', 'max:5000'],
            'estimated_time' => ['nullable', 'string', 'max:120'],
            'photos' => ['nullable', 'array', 'max:'.SearchRequestResponse::MAX_PHOTOS],
            'photos.*' => ['file', 'max:10240', 'mimes:jpg,jpeg,png,webp,pdf'],
        ]);
        if ($validator->fails()) {
            return $this->page($link, $codigo, null, $validator->errors()->all(), $request->except('photos'), 422);
        }
        $data = $validator->validated();
        $data['links'] = array_values(array_filter(array_map('trim', preg_split('/\s+/', (string) ($data['links'] ?? ''))), fn ($l) => filter_var($l, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $l)));
        $stored = [];
        foreach ((array) $request->file('photos', []) as $file) {
            $stored[] = $file->store('clarto/solicitudes/propuestas/'.$recipient->search_request_id, SearchRequest::DISK);
        }
        $data['photos'] = $stored ?: null;

        try {
            SearchRequests::respond($recipient, $data, null, 'enlace');
        } catch (RuntimeException $e) {
            Storage::disk(SearchRequest::DISK)->delete($stored);   // refused (closed meanwhile): nothing is kept

            return $this->page($link, $codigo, null, [$e->getMessage()], $request->except('photos'), 409);
        }

        return redirect('/s/'.$codigo.'?enviada=1', 303)->withHeaders(InformeEnlaceController::headers("default-src 'none'"));
    }

    public function attachment(string $codigo, int $index): Response
    {
        [$link, $refusal] = $this->resolve($codigo);
        if ($refusal !== null) {
            return $refusal;
        }
        $request = $link->recipient->request;
        $path = array_values((array) $request->attachments)[$index] ?? abort(404);
        abort_unless(Storage::disk(SearchRequest::DISK)->exists($path), 404);

        return Storage::disk(SearchRequest::DISK)->response($path, $request->attachment_names[$path] ?? basename($path), InformeEnlaceController::headers("default-src 'none'"), 'attachment');
    }

    /** @return array{0: ?SearchRequestLink, 1: ?Response} */
    private function resolve(string $codigo): array
    {
        $found = SearchRequestLink::resolve($codigo);
        if ($found['estado'] === 'activo') {
            app()->setLocale(SearchRequests::locale($found['link']->recipient->collaborator));

            return [$found['link'], null];
        }
        if ($found['link'] !== null) {
            app()->setLocale(SearchRequests::locale($found['link']->recipient->collaborator));
        }

        return [null, $this->render(['estado' => $found['estado'], 'request' => $found['link']?->recipient?->request], $found['estado'] === 'desconocido' ? 404 : 410)];
    }

    /** @param list<string> $errors */
    private function page(SearchRequestLink $link, string $codigo, ?string $notice, array $errors = [], array $old = [], int $status = 200): Response
    {
        $recipient = $link->recipient;
        $responses = SearchRequestResponse::query()->where('recipient_id', $recipient->getKey())->latest('id')->get();

        return $this->render([
            'estado' => 'activo', 'request' => $recipient->request, 'collaborator' => $recipient->collaborator, 'responses' => $responses,
            'codigo' => $codigo, 'notice' => $notice, 'problems' => $errors, 'old' => $old,
        ], $status);
    }

    private function render(array $data, int $status): Response
    {
        $nonce = Str::random(24);
        $html = view('solicitudes.enlace', $data + ['nonce' => $nonce])->render();

        return response($html, $status, InformeEnlaceController::headers(
            "default-src 'none'; style-src 'nonce-{$nonce}'; img-src data:; base-uri 'none'; form-action 'self'; frame-ancestors 'none'",
        ) + ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
