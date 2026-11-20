<?php

declare(strict_types=1);

namespace App\Portals\Pisos;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * pisos.com Web API v5 (https://api.pisos.com/v5/Help): POST/PUT inmueble, POST inmueble/deactivate/{id}/{userId},
 * GET inmueble/{id}, POST media/saveMultimediaXml, GET usuarios/{userId}/inmuebles. The ApiKey goes in the
 * query string (apiKey=), as pisos.com's own test pages do. Response bodies are not documented: the
 * listing id is read from ID and validation from Respuesta {StatusCodeId, Text, ErrorCodeId}.
 */
final class PisosApiTransport implements PisosTransport
{
    public function create(array $inmueble): PisosResult
    {
        return $this->send(fn (PendingRequest $h) => $h->post($this->url('inmueble'), $inmueble));
    }

    public function update(array $inmueble): PisosResult
    {
        return $this->send(fn (PendingRequest $h) => $h->put($this->url('inmueble'), $inmueble));
    }

    public function deactivate(string $id): PisosResult
    {
        return $this->send(fn (PendingRequest $h) => $h->post($this->url('inmueble/deactivate/'.rawurlencode($id).'/'.(int) config('pisos.user_id'))), $id);
    }

    public function get(string $id): PisosResult
    {
        return $this->send(fn (PendingRequest $h) => $h->get($this->url('inmueble/'.rawurlencode($id))), $id);
    }

    public function savePhotos(string $id, string $reference, array $photos): PisosResult
    {
        $medias = array_map(fn (array $p): array => [
            'Tipo' => 1,        // Foto
            'Subtipo' => 0,
            'Orden' => $p['orden'],
            'Status' => 1,      // Visible
            'Origen' => 4,      // Pasarelas
            'Datos' => ['UrlCDATA' => $p['url'], 'IdReferencia' => $reference],
        ], $photos);

        return $this->send(fn (PendingRequest $h) => $h->post($this->url('media/saveMultimediaXml'), [
            'idInmueble' => $id,
            'idUsuario' => (string) config('pisos.user_id'),
            'mediasList' => $medias,
        ]), $id);
    }

    public function findByReference(string $reference): ?string
    {
        $result = $this->send(fn (PendingRequest $h) => $h->get($this->url('usuarios/'.(int) config('pisos.user_id').'/inmuebles')));
        foreach ((array) ($result->raw['items'] ?? $result->raw) as $item) {
            if (is_array($item) && str_contains((string) ($item['Descripcion'] ?? ''), $reference) && isset($item['ID'])) {
                return (string) $item['ID'];
            }
        }

        return null;
    }

    private function url(string $path): string
    {
        return rtrim((string) config('pisos.base_url'), '/').'/'.$path.'?apiKey='.rawurlencode((string) config('pisos.api_key'));
    }

    /** @param callable(PendingRequest): Response $call */
    private function send(callable $call, ?string $id = null): PisosResult
    {
        try {
            $response = $call(Http::acceptJson()->asJson()->timeout((int) config('pisos.timeout', 30)));
        } catch (ConnectionException $e) {
            return new PisosResult('unconfirmed', $id, message: 'Sin respuesta de pisos.com: '.$e->getMessage());
        }

        $body = (array) ($response->json() ?? []);
        $respuesta = (array) ($body['Respuesta'] ?? []);
        $errorCode = filled($respuesta['ErrorCodeId'] ?? null) ? (string) $respuesta['ErrorCodeId'] : null;
        $text = $respuesta['Text'] ?? $body['Message'] ?? $body['message'] ?? null;
        $statusCode = $respuesta['StatusCodeId'] ?? null;
        $validationFailed = $errorCode !== null || (is_numeric($statusCode) && (int) $statusCode >= 400) || (is_string($statusCode) && ! in_array(strtolower($statusCode), ['ok', '200'], true));

        if ($response->serverError()) {
            return new PisosResult('error', $id, message: 'pisos.com no está disponible (HTTP '.$response->status().')', httpStatus: $response->status(), raw: $body);
        }
        if ($response->clientError() || $validationFailed) {
            return new PisosResult('rejected', $id, message: (string) ($text ?: 'Rechazado por pisos.com (HTTP '.$response->status().')'), errorCode: $errorCode, httpStatus: $response->status(), raw: $body);
        }

        return new PisosResult(
            'ok',
            isset($body['ID']) ? (string) $body['ID'] : $id,
            isset($body['Estado']) ? (int) $body['Estado'] : null,
            is_string($text) ? $text : null,
            httpStatus: $response->status(),
            raw: $body,
        );
    }
}
