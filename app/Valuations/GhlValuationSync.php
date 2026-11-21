<?php

declare(strict_types=1);

namespace App\Valuations;

use App\Acquisition\GhlClient;
use App\Acquisition\GhlError;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * GoHighLevel for a valuation request (GHL strategy orquestacion/ghl-estrategia.md, annex A; docs/clarto/captacion-ghl.md):
 *
 * - ONE contact per person (GHL dedupes by e-mail/phone), tagged through POST /contacts/{id}/tags (upsert and PUT
 *   overwrite tags, which would erase the ones the workflows put), with the lead record fields, the consents (rules of
 *   §7.3: «Sí» + date when ticked; «No» only on an empty field; never over «Sí»/«Revocado») and a «last valuation» copy
 *   (val_*) for the workflow texts.
 * - ONE opportunity per PROPERTY (L-1): property_key = rc:<cadastral ref> or dir:<sha1 of the normalised address>.
 *   Another request for the same property updates its open opportunity (solicitudes_ids + a note); a won or lost one
 *   gets a new opportunity. A retry never duplicates: the id is saved as soon as GHL returns it, and before creating
 *   one the contact's opportunities are searched (this request, then this property); 400 OPPORTUNITY_NO_DUPLICATE
 *   reuses meta.existingId.
 * - After delivering, reportDelivery() writes the channel states, moves «Solicitud recibida» → «Valoración entregada»
 *   and tags «entrega-error» when a channel still fails after 2 hours (L-3).
 *
 * Won in GHL → the existing GhlHandoff flow (owner, draft property, TARMIO task).
 */
final class GhlValuationSync
{
    public const SOURCE = 'Web clarto.es · Valoración automática';

    /** Tags of every request (annex A §A.4); `valoracion-web` is no longer used. */
    public const TAGS = ['valoracion-gratuita', 'origen-web-valoracion', 'propietario', 'interes-venta'];

    public const PLAZOS = ['menos_3m' => 'Menos de 3 meses', '3_6m' => 'De 3 a 6 meses', '6_12m' => 'De 6 a 12 meses', 'mas_12m' => 'Más de 12 meses', 'solo_informacion' => 'Solo quiero conocer el valor'];

    public const RELACIONES = ['propietario' => 'Propietario', 'copropietario_heredero' => 'Copropietario o heredero', 'familiar_representante' => 'Familiar o representante', 'otra' => 'Otra'];

    private const EN_VENTA = ['no' => 'Sin anunciar', 'propietario' => 'Anunciado por su cuenta', 'por_su_cuenta' => 'Anunciado por su cuenta', 'agencia' => 'Con otra agencia'];

    private const OCUPACION = ['propietario' => 'Vive en él', 'habitual' => 'Vive en él', 'vacia' => 'Vacío', 'alquilada' => 'Alquilado'];

    private const CANALES = ['whatsapp' => 'WhatsApp', 'telefono' => 'Teléfono', 'email' => 'Correo electrónico'];

    private const TEMPERATURAS = ['Frío' => 1, 'Templado' => 2, 'Caliente' => 3];

    public function __construct(private readonly GhlClient $ghl = new GhlClient) {}

    public static function configured(): bool
    {
        return filled(config('ghl.token')) && filled(config('ghl.location_id')) && filled(config('ghl.pipeline_id')) && filled(config('valoraciones.ghl.stage_id'));
    }

    /** Short stable suffix of the request id that identifies its opportunity (same as the web's requestTag()). */
    public static function tag(string $requestId): string
    {
        return '#'.substr(str_replace('-', '', $requestId), 0, 10);
    }

    /** L-1: rc:<20> when the dwelling was confirmed in Catastro, else dir:sha1(INE municipality + street + number + door). */
    public static function propertyKey(ValuationRequest $request): string
    {
        $rc = strtoupper((string) ($request->catastro['rc'] ?? $request->property['rc'] ?? ''));
        if (preg_match('/^[0-9A-Z]{20}$/', $rc)) {
            return 'rc:'.$rc;
        }
        $norm = fn (?string $s): string => trim((string) preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii((string) $s))));

        return 'dir:'.sha1(implode('|', [
            $norm((string) ($request->catastro['municipio_ine'] ?? $request->catastro['municipio'] ?? $request->property['municipio'] ?? '')),
            $norm($request->address()),
        ]));
    }

    /** Requests created before GHL_ALTA_DESDE are «previous» (L-7): synced by hand only, never nurtured. */
    public static function origin(ValuationRequest $request): string
    {
        $since = config('ghl.alta_desde');

        return filled($since) && $request->created_at !== null && $request->created_at->lt(Carbon::parse((string) $since)->startOfDay())
            ? 'web_valoracion_previa' : 'web_valoracion';
    }

    public function sync(ValuationRequest $request): void
    {
        if (! self::configured()) {
            $request->forceFill(['ghl_status' => 'sin_configurar', 'ghl_error' => null])->save();

            return;
        }

        Cache::lock('clarto:valoracion-ghl:'.$request->request_id, 60)->block(20, function () use ($request): void {
            $request->refresh();
            $request->increment('ghl_attempts');
            try {
                $request->forceFill(['property_key' => self::propertyKey($request)])->save();
                $contactId = $request->ghl_contact_id ?: $this->upsertContact($request);
                $request->forceFill(['ghl_contact_id' => $contactId])->save();
                $this->ghl->addTags($contactId, self::TAGS);
                $this->syncOpportunity($request, $contactId);
                $contact = $this->ghl->contact($contactId);
                $this->ghl->call('PUT', '/contacts/'.rawurlencode($contactId), ['customFields' => $this->ghl->fieldsPayload('contact', $this->contactValues($request, $contact))]);
                $request->forceFill(['ghl_status' => 'sincronizada', 'ghl_error' => null, 'ghl_synced_at' => now()])->save();
            } catch (Throwable $e) {
                report($e);
                $request->forceFill(['ghl_status' => 'error', 'ghl_error' => mb_strimwidth($e->getMessage(), 0, 300, '…')])->save();
            }
        });
    }

    /**
     * L-3: tell GHL how the delivery went (channel states, stage «Valoración entregada», tag «entrega-error» after 2 h).
     * Only when something changed since the last report; a failure leaves the request to be retried.
     */
    public function reportDelivery(ValuationRequest $request): void
    {
        $request->refresh();
        $run = $request->currentRun;
        if (! self::configured() || $run === null || blank($request->ghl_opportunity_id) || blank($request->ghl_contact_id) || $request->delivery === null) {
            return;
        }
        $state = ValuationDelivery::state($request);
        if ($state['run'] === null) {
            return;   // nothing attempted yet
        }
        $email = (string) $state['email']['estado'];
        $whatsApp = (string) $state['whatsapp']['estado'];
        $anySent = in_array(ValuationDelivery::SENT, [$email, $whatsApp], true);
        $failing = array_intersect([$email, $whatsApp], [ValuationDelivery::ERROR, ValuationDelivery::NO_LINE]) !== [];
        $overdue = $failing && $request->created_at !== null && $request->created_at->lte(now()->subHours(2));
        $hash = md5(json_encode([$state['run'], $email, $whatsApp, $overdue]) ?: '');
        if ($request->ghl_delivery_reported === $hash) {
            return;
        }

        try {
            $sentAt = collect([$state['email'], $state['whatsapp']])->where('estado', ValuationDelivery::SENT)->pluck('fecha')->filter()->sort()->first();
            $body = ['customFields' => $this->ghl->fieldsPayload('opportunity', [
                'entrega_email_estado' => $email, 'entrega_whatsapp_estado' => $whatsApp, 'informe_enviado_at' => $sentAt,
            ])];
            if ($anySent && $run->status === 'estimada' && filled(config('ghl.stage_entregada'))) {
                $opportunity = $this->ghl->opportunity((string) $request->ghl_opportunity_id);
                if (($opportunity['pipelineStageId'] ?? null) === config('valoraciones.ghl.stage_id') && ($opportunity['status'] ?? 'open') === 'open') {
                    $body += ['pipelineId' => config('ghl.pipeline_id'), 'pipelineStageId' => config('ghl.stage_entregada')];
                }
            }
            $this->ghl->call('PUT', '/opportunities/'.rawurlencode((string) $request->ghl_opportunity_id), $body);
            if ($anySent) {
                $this->ghl->note((string) $request->ghl_contact_id, "Valoración {$request->reference} entregada · correo: {$email} · WhatsApp: {$whatsApp}");
            }
            if ($overdue) {
                $this->ghl->addTags((string) $request->ghl_contact_id, ['entrega-error']);
            } elseif (! $failing && $anySent) {
                $this->ghl->removeTags((string) $request->ghl_contact_id, ['entrega-error']);
            }
            $request->forceFill(['ghl_delivery_reported' => $hash])->save();
        } catch (Throwable $e) {
            report($e);
            $request->forceFill(['ghl_status' => 'error', 'ghl_error' => mb_strimwidth('Entrega no comunicada a GHL: '.$e->getMessage(), 0, 300, '…')])->save();
        }
    }

    /** L-15: report opens to the opportunity; the second open raises the contact's temperature one level. */
    public function reportOpens(ValuationRequest $request): void
    {
        if (! self::configured() || blank($request->ghl_opportunity_id)) {
            return;
        }
        try {
            $this->update((string) $request->ghl_opportunity_id, ['informe_aperturas' => $request->report_opens]);
            if ((int) $request->report_opens === 2 && filled($request->ghl_contact_id)) {
                $current = $this->ghl->value($this->ghl->contact((string) $request->ghl_contact_id), 'contact', 'temperatura');
                $next = ['Frío' => 'Templado', 'Templado' => 'Caliente'][$current] ?? null;
                if ($next !== null) {
                    $this->ghl->call('PUT', '/contacts/'.rawurlencode((string) $request->ghl_contact_id), ['customFields' => $this->ghl->fieldsPayload('contact', ['temperatura' => $next])]);
                }
            }
        } catch (Throwable $e) {
            report($e);   // a counter: never worth a retry
        }
    }

    private function upsertContact(ValuationRequest $request): string
    {
        $c = (array) $request->contact;
        $name = trim((string) ($c['nombre'] ?? ''));
        $parts = preg_split('/\s+/', $name) ?: [];
        $answer = $this->ghl->call('POST', '/contacts/upsert', array_filter([
            'locationId' => config('ghl.location_id'),
            'firstName' => array_shift($parts) ?: null,
            'lastName' => implode(' ', $parts) ?: null,
            'name' => $name ?: null,
            'email' => $c['email'] ?? null,
            'phone' => $c['telefono'] ?? null,
            'source' => self::SOURCE,
        ], fn ($v): bool => $v !== null && $v !== ''));
        $id = (string) ($answer['contact']['id'] ?? '');
        if ($id === '') {
            throw new RuntimeException('GHL no ha devuelto el contacto');
        }

        return $id;
    }

    private function syncOpportunity(ValuationRequest $request, string $contactId): void
    {
        $id = $request->ghl_opportunity_id;
        $existing = null;
        if (blank($id)) {
            [$id, $existing] = $this->findOpportunity($request, $contactId);
        }
        $fresh = blank($request->ghl_opportunity_id);   // first sync of this request: advisor-editable fields are written too
        $history = $existing !== null ? $this->ghl->value($existing, 'opportunity', 'solicitudes_ids') : null;
        $fields = $this->opportunityValues($request, $fresh, $history);

        $created = [];
        if ($id === null) {
            try {
                $created = $this->ghl->call('POST', '/opportunities/', [
                    'locationId' => config('ghl.location_id'), 'pipelineId' => config('ghl.pipeline_id'), 'pipelineStageId' => config('valoraciones.ghl.stage_id'),
                    'contactId' => $contactId, 'status' => 'open', 'source' => self::SOURCE, 'name' => $this->name($request),
                    'customFields' => $this->ghl->fieldsPayload('opportunity', $fields),
                ]);
                $id = (string) ($created['opportunity']['id'] ?? '');
            } catch (GhlError $e) {
                $id = (string) ($e->response->json('meta.existingId') ?? $e->response->json('existingId') ?? '');
                if ($e->response->status() !== 400 || $id === '') {
                    throw $e;
                }
            }
            if ($id === '') {
                throw new RuntimeException('GHL no ha devuelto la oportunidad');
            }
            $request->forceFill(['ghl_opportunity_id' => $id])->save();   // before anything else can fail
            if (! isset($created['opportunity']['id'])) {
                $this->update($id, $fields);   // reused through OPPORTUNITY_NO_DUPLICATE
            }
        } else {
            $request->forceFill(['ghl_opportunity_id' => $id])->save();
            $this->update($id, $fields);
            if ($existing !== null && ! str_contains((string) $history, $request->request_id) && ! str_contains((string) ($existing['name'] ?? ''), self::tag($request->request_id))) {
                $this->ghl->note($contactId, "Nueva solicitud {$request->reference} sobre el mismo inmueble ({$request->shortAddress()}).");
            }
        }
        $this->verifySaved($id, $request);
    }

    /** @param array<string, mixed> $fields */
    private function update(string $id, array $fields): void
    {
        $this->ghl->call('PUT', '/opportunities/'.rawurlencode($id), ['customFields' => $this->ghl->fieldsPayload('opportunity', $fields)]);
    }

    /** L-0: GHL ignores unknown custom field properties silently, so read one value back. */
    private function verifySaved(string $id, ValuationRequest $request): void
    {
        if (! isset($this->ghl->fieldIds('opportunity')['inmueble_clave'])) {
            return;
        }
        $saved = $this->ghl->value($this->ghl->opportunity($id), 'opportunity', 'inmueble_clave');
        if ($saved !== $request->property_key) {
            throw new RuntimeException('GHL no ha guardado los campos de la oportunidad (L-0): revisar el formato de customFields');
        }
    }

    /**
     * The open opportunity of this request (id saved elsewhere lost, legacy name tag or solicitud_id), else the OPEN
     * opportunity of the same property. Won or lost ones are left alone: a new opportunity is created.
     *
     * @return array{0: ?string, 1: ?array<string, mixed>}
     */
    private function findOpportunity(ValuationRequest $request, string $contactId): array
    {
        $answer = $this->ghl->get('/opportunities/search?'.http_build_query([
            'location_id' => config('ghl.location_id'), 'pipeline_id' => config('ghl.pipeline_id'), 'contact_id' => $contactId, 'limit' => 100,
        ]));
        $opportunities = (array) ($answer['opportunities'] ?? []);
        foreach ($opportunities as $o) {
            if (str_contains((string) ($o['name'] ?? ''), self::tag($request->request_id))
                || $this->ghl->value($o, 'opportunity', 'solicitud_id') === $request->request_id
                || str_contains((string) $this->ghl->value($o, 'opportunity', 'solicitudes_ids'), $request->request_id)) {
                return [(string) $o['id'], $o];
            }
        }
        foreach ($opportunities as $o) {
            if (($o['status'] ?? 'open') === 'open' && $this->ghl->value($o, 'opportunity', 'inmueble_clave') === $request->property_key) {
                return [(string) $o['id'], $o];
            }
        }

        return [null, null];
    }

    private function name(ValuationRequest $request): string
    {
        $p = (array) $request->property;

        return trim((string) preg_replace('/\s+/', ' ', 'Valoración · '.($request->catastro['municipio'] ?? $p['municipio'] ?? '').' · '.($p['tipo'] ?? '').' '.self::tag($request->request_id)));
    }

    /** @return array<string, mixed> annex A §A.2 */
    private function opportunityValues(ValuationRequest $request, bool $fresh, ?string $history): array
    {
        $p = (array) $request->property;
        $c = (array) $request->contact;
        $run = $request->currentRun;
        $r = (array) ($run?->result ?? []);
        $eur = fn ($v): string => ReportData::eur($v);
        $utm = (array) ($request->attribution['utm'] ?? []);
        $a = (array) $request->attribution;
        $expires = $run?->pdf_path !== null ? now()->addDays(max(1, (int) config('valoraciones.informe_dias', 30)))->format('Y-m-d') : null;
        $values = [
            'solicitud_id' => $request->request_id,
            'solicitudes_ids' => implode(', ', array_values(array_unique(array_filter([...explode(',', str_replace(' ', '', (string) $history)), $request->request_id])))),
            'inmueble_clave' => $request->property_key,
            'referencia_valoracion' => $request->reference,
            'estado_valoracion' => $run?->status ?? $request->status,
            'operacion' => $request->operation,
            'tipo_inmueble' => $p['tipo'] ?? null,
            'direccion' => $request->address(),
            'direccion_resumida' => $request->shortAddress(),
            'zona' => mb_convert_case((string) ($request->catastro['municipio'] ?? $p['municipio'] ?? ''), MB_CASE_TITLE),
            'municipio' => $request->catastro['municipio'] ?? $p['municipio'] ?? null,
            'codigo_postal' => $p['codigo_postal'] ?? $request->catastro['codigo_postal'] ?? null,
            'referencia_catastral' => $request->catastro['rc'] ?? null,
            'oficina' => $request->office(),
            'superficie_m2' => $r['calculo']['superficie_m2'] ?? $p['superficie_construida_m2'] ?? $p['superficie_util_m2'] ?? null,
            'dormitorios' => $p['dormitorios'] ?? null,
            'banos' => $p['banos'] ?? null,
            'precio_estimado' => $r['precio_central'] ?? null,
            'precio_minimo' => $r['intervalo']['min'] ?? null,
            'precio_maximo' => $r['intervalo']['max'] ?? null,
            'intervalo' => isset($r['intervalo']) ? $eur($r['intervalo']['min']).' – '.$eur($r['intervalo']['max']) : null,
            'suficiencia' => $r['suficiencia'] ?? null,
            'version_calculo' => $r['version_calculo'] ?? $run?->version,
            'fecha_valoracion' => $run?->created_at?->format('Y-m-d'),
            'informe_url' => $request->reportUrl(),
            'informe_caduca' => $expires,
            'datos_aportados' => mb_strimwidth((string) json_encode($p, JSON_UNESCAPED_UNICODE), 0, 2000, '…'),
            'origen' => self::origin($request),
            'utm_source' => $utm['source'] ?? null,
            'utm_medium' => $utm['medium'] ?? null,
            'utm_campaign' => $utm['campaign'] ?? null,
            'landing' => $a['landing'] ?? null,
            'atribucion_clic' => implode(' · ', array_map(fn (string $k): string => $k.': '.$a[$k], array_filter(['gclid', 'fbclid', 'cta'], fn (string $k): bool => filled($a[$k] ?? null)))) ?: null,
            'comentario_propietario' => filled($c['comentario'] ?? null) ? mb_substr(trim(strip_tags((string) $c['comentario'])), 0, 300) : null,
            'alertas' => implode(', ', self::alerts($request)) ?: null,
            'informe_aperturas' => $request->report_opens ?: null,
        ];
        // Fields the advisor or the workflows may change later: written by the first sync of each request only.
        if ($fresh) {
            $values += [
                'plazo_venta' => self::PLAZOS[$c['plazo_venta'] ?? ''] ?? 'No indicado',
                'relacion_inmueble' => self::RELACIONES[$c['relacion'] ?? ''] ?? 'No indicada',
                'situacion_comercial' => self::EN_VENTA[$p['en_venta'] ?? ''] ?? 'No indicado',
                'ocupacion' => self::OCUPACION[$p['ocupacion'] ?? ''] ?? 'No indicado',
            ];
        }

        return $values;
    }

    /**
     * Annex A §A.3 (lead record, preferences, consents, «last valuation» copy). Fields that must never be overwritten
     * are written only when empty in GHL; the val_* copy only from the person's most recent request.
     *
     * @param  array<string, mixed>  $contact  current contact in GHL
     * @return array<string, mixed>
     */
    private function contactValues(ValuationRequest $request, array $contact): array
    {
        $c = (array) $request->contact;
        $consent = (array) $request->consent;
        $commercial = (array) ($consent['comercial'] ?? []);
        $has = fn (string $key): bool => $this->ghl->value($contact, 'contact', $key) !== null;
        $now = ($consent['fecha'] ?? null) ? Carbon::parse((string) $consent['fecha'])->toIso8601String() : now()->toIso8601String();
        $origin = 'clarto.es/inmuebles/valoraciones · '.$request->reference;
        $requests = ValuationRequest::query()->where('ghl_contact_id', $request->ghl_contact_id);
        $franja = Str::lower(Str::ascii((string) ($c['franja'] ?? '')));
        $interests = array_values(array_unique(array_filter([...array_map('trim', explode(',', (string) $this->ghl->value($contact, 'contact', 'intereses'))), 'Vender'])));
        $temperature = self::temperature($request);
        $current = $this->ghl->value($contact, 'contact', 'temperatura');

        $values = [
            'num_valoraciones' => (clone $requests)->count(),
            'num_inmuebles' => (clone $requests)->whereNotNull('property_key')->distinct()->count('property_key'),
            'intereses' => $interests,
            'canal_preferido' => self::CANALES[$c['canal'] ?? ''] ?? null,
            'franja_preferida' => match (true) {
                $franja === '' => null,
                str_starts_with($franja, 'man') => 'Mañana',
                str_starts_with($franja, 'tar') => 'Tarde',
                default => 'Indiferente',
            },
            'consentimiento_tratamiento_version' => $consent['version'] ?? null,
            'consentimiento_tratamiento_fecha' => $now,
            'consentimiento_origen' => $origin,
            'temperatura' => (self::TEMPERATURAS[$temperature] ?? 0) > (self::TEMPERATURAS[$current] ?? 0) ? $temperature : null,
        ];
        if (! $has('fuente_original')) {
            $values['fuente_original'] = 'Web · valoración';
        }
        if (! $has('campana_original')) {
            $values['campana_original'] = $request->attribution['utm']['campaign'] ?? null;
        }
        if (! $has('primera_valoracion_fecha')) {
            $values['primera_valoracion_fecha'] = $request->created_at?->format('Y-m-d');
        }
        if (! $has('idioma')) {
            $values['idioma'] = 'es · Español';
        }
        foreach (['email', 'whatsapp'] as $channel) {
            if (($commercial[$channel] ?? false) === true) {
                $values["consentimiento_comercial_{$channel}"] = 'Sí';
                $values["consentimiento_comercial_{$channel}_fecha"] = $now;
            } elseif (! $has("consentimiento_comercial_{$channel}")) {
                $values["consentimiento_comercial_{$channel}"] = 'No';
            }
        }
        if (in_array(true, [$commercial['email'] ?? false, $commercial['whatsapp'] ?? false], true)) {
            $values['consentimiento_comercial_version'] = $commercial['version'] ?? $consent['version'] ?? null;
            $values['consentimiento_comercial_texto'] = trim(implode("\n", array_filter([
                ($commercial['email'] ?? false) ? ($commercial['texto_email'] ?? null) : null,
                ($commercial['whatsapp'] ?? false) ? ($commercial['texto_whatsapp'] ?? null) : null,
            ]))) ?: null;
            $values['consentimiento_comercial_origen'] = $origin;
        }

        $newer = ValuationRequest::query()->where('ghl_contact_id', $request->ghl_contact_id)->where('id', '>', $request->getKey())->exists();
        if (! $newer) {
            $r = (array) ($request->currentRun?->result ?? []);
            $office = $request->office();
            $values += [
                'val_referencia' => $request->reference,
                'val_direccion_resumida' => $request->shortAddress(),
                'val_zona' => mb_convert_case((string) ($request->catastro['municipio'] ?? $request->property['municipio'] ?? ''), MB_CASE_TITLE),
                'val_oficina' => $office,
                'val_precio_minimo_txt' => isset($r['intervalo']) ? ReportData::eur($r['intervalo']['min']) : null,
                'val_precio_maximo_txt' => isset($r['intervalo']) ? ReportData::eur($r['intervalo']['max']) : null,
                'val_estado' => $request->currentRun?->status ?? $request->status,
                'val_plazo_venta' => self::PLAZOS[$c['plazo_venta'] ?? ''] ?? 'No indicado',
                'val_informe_url' => $request->reportUrl(),
                'val_informe_caduca' => $request->currentRun?->pdf_path !== null ? now()->addDays(max(1, (int) config('valoraciones.informe_dias', 30)))->format('Y-m-d') : null,
                'val_enlace_cita' => Office::bookingUrl($office, 'correo1'),
            ];
        }

        return $values;
    }

    /** L-14 (captacion.md §6): Caliente / Templado / Frío from the selling horizon and the market situation. */
    public static function temperature(ValuationRequest $request): string
    {
        $plazo = (string) ($request->contact['plazo_venta'] ?? '');
        $enVenta = (string) ($request->property['en_venta'] ?? '');

        return match (true) {
            in_array($plazo, ['menos_3m', '3_6m'], true) => 'Caliente',
            in_array($enVenta, ['propietario', 'por_su_cuenta'], true) && $plazo === '6_12m' => 'Caliente',
            $plazo === '6_12m', $enVenta === 'agencia', ($request->currentRun?->status ?? $request->status) === 'revision' => 'Templado',
            default => 'Frío',
        };
    }

    /** @return list<string> flags for the advisor (captacion.md §6) */
    public static function alerts(ValuationRequest $request): array
    {
        $p = (array) $request->property;
        $c = (array) $request->contact;

        return array_values(array_filter([
            ($c['relacion'] ?? null) === 'copropietario_heredero' ? 'herencia' : null,
            ($p['ocupacion'] ?? null) === 'alquilada' ? 'alquilada' : null,
            ($p['ocupacion'] ?? null) === 'vacia' ? 'vacia' : null,
            ($p['en_venta'] ?? null) === 'agencia' ? 'en-venta-agencia' : null,
            in_array($p['en_venta'] ?? null, ['propietario', 'por_su_cuenta'], true) ? 'en-venta-particular' : null,
            Office::outOfArea((string) ($p['codigo_postal'] ?? $request->catastro['codigo_postal'] ?? '')) ? 'fuera-de-zona' : null,
            ($request->currentRun?->status ?? $request->status) === 'revision' ? 'revision' : null,
            filled($c['comentario'] ?? null) ? 'comentario' : null,
        ]));
    }
}
