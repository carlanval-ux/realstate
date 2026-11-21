<?php

declare(strict_types=1);

namespace App\Documents;

use App\Albaranes\Albaran;
use App\Filament\Resources\FormTemplateResource;
use App\Owners\OwnerProfile;
use App\Rentals\Models\Occupancy;
use App\Rentals\Models\OccupancyPerson;
use App\Rentals\Models\RentalDocument;
use App\Rentals\RentalAccess;
use App\Signing\SignatureRequest;
use App\Valuations\ValuationRequest;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Liberu\RealEstate\MediaAndDocuments\Models\DocumentEnvelope;
use Liberu\RealEstate\MediaAndDocuments\Models\DocumentParticipant;
use Liberu\RealEstate\Parties\Models\Party;
use Throwable;

/**
 * What other features generated for one client, read-only: signed documents, valuation reports, albaranes, rental
 * documents (and the module's signature envelopes). Each row links to its source and, when there is a file, to a
 * download that checks the same permission as the source. Rows the user may not open are left out.
 */
final class ClientFiles
{
    /** @return list<array{type: string, title: string, date: ?CarbonInterface, source: ?string, download: ?string}> */
    public static function generated(Party $party, ?object $user = null): array
    {
        $user ??= auth()->user();
        $rows = [...self::signed($party, $user), ...self::valuations($party, $user), ...self::albaranes($party, $user), ...self::rentals($party, $user), ...self::envelopes($party)];
        usort($rows, fn (array $a, array $b): int => ($b['date']?->getTimestamp() ?? 0) <=> ($a['date']?->getTimestamp() ?? 0));

        return $rows;
    }

    /**
     * Properties linked to the client: owned (mandates, valuations, GHL), rented, signed or invoiced.
     *
     * @return array<int, list<string>> property id => relation keys
     */
    public static function linkedProperties(Party $party): array
    {
        $links = [];
        $add = function (iterable $ids, string $relation) use (&$links): void {
            foreach ($ids as $id) {
                if ($id !== null && ! in_array($relation, $links[(int) $id] ?? [], true)) {
                    $links[(int) $id][] = $relation;
                }
            }
        };
        $add(OwnerProfile::propertyIds($party), 'owner');
        $add(Occupancy::query()->where('team_id', $party->team_id)->whereIn('id', self::occupancyIds($party))->pluck('property_id'), 'rental');
        $add(SignatureRequest::query()->where('team_id', $party->team_id)->where('party_id', $party->getKey())->pluck('property_id'), 'signature');
        $add(Albaran::query()->where('team_id', $party->team_id)->where('party_id', $party->getKey())->pluck('property_id'), 'albaran');

        return $links;
    }

    private static function signed(Party $party, ?object $user): array
    {
        if (! FormTemplateResource::staff()) {   // same rule as the signatures screen
            return [];
        }
        $rows = [];
        foreach (SignatureRequest::query()->with('documents')->where('team_id', $party->team_id)->where('party_id', $party->getKey())->get() as $request) {
            $source = self::url(\App\Filament\Resources\SignatureRequestResource::class, $request);
            foreach ($request->documents as $doc) {
                $rows[] = ['type' => __('documentos.generated.signed_'.($doc->kind === 'auditoria' ? 'audit' : 'document')), 'title' => $request->title,
                    'date' => $doc->created_at, 'source' => $source, 'download' => route('clarto.documentos.descargar', ['source' => 'firma', 'id' => $doc->getKey()])];
            }
            if ($request->documents->isEmpty() && filled($request->document_path)) {
                $rows[] = ['type' => __('documentos.generated.sent_for_signature').' · '.$request->statusLabel(), 'title' => $request->title,
                    'date' => $request->sent_at ?? $request->created_at, 'source' => $source, 'download' => route('clarto.documentos.descargar', ['source' => 'firma-enviado', 'id' => $request->getKey()])];
            }
        }

        return $rows;
    }

    private static function valuations(Party $party, ?object $user): array
    {
        $ghl = array_values(array_filter([$party->metadata['ghl_contact_id'] ?? null, ...(array) ($party->metadata['ghl_contact_ids'] ?? [])]));
        $properties = array_keys(self::linkedProperties($party));
        $requests = ValuationRequest::query()->visibleTo($user)->with('currentRun')->where('team_id', $party->team_id)
            ->where(function ($query) use ($ghl, $properties, $party): void {
                $query->whereIn('property_id', $properties ?: [0])->orWhereIn('ghl_contact_id', $ghl ?: ['']);
                if (filled($party->email)) {
                    $query->orWhere('contact->email', $party->email);
                }
            })->get();

        return $requests->map(fn (ValuationRequest $v): array => [
            'type' => __('documentos.generated.valuation'), 'title' => $v->reference.' · '.$v->address(),
            'date' => $v->currentRun?->created_at ?? $v->created_at,
            'source' => self::url(\App\Filament\Resources\ValuationRequestResource::class, $v),
            'download' => $v->currentRun?->pdf_path ? route('clarto.valoraciones.informe', ['valuationRequest' => $v->getKey()]) : null,
        ])->all();
    }

    private static function albaranes(Party $party, ?object $user): array
    {
        return Albaran::query()->where('team_id', $party->team_id)->where('party_id', $party->getKey())->latest()->get()
            ->map(fn (Albaran $a): array => [
                'type' => __('documentos.generated.albaran').' · '.$a->statusLabel(), 'title' => $a->number,
                'date' => $a->created_at, 'source' => self::url(\App\Filament\Resources\AlbaranResource::class, $a),
                'download' => route('clarto.documentos.descargar', ['source' => 'albaran', 'id' => $a->getKey()]),
            ])->all();
    }

    private static function rentals(Party $party, ?object $user): array
    {
        if (! RentalAccess::allows($user, RentalAccess::CONTRACTS)) {
            return [];
        }

        return RentalDocument::query()->with('occupancy')->where('team_id', $party->team_id)->whereIn('occupancy_id', self::occupancyIds($party))->latest()->get()
            ->map(fn (RentalDocument $d): array => [
                'type' => __('documentos.generated.rental').' · '.__('clarto.alquileres.document_kinds.'.$d->kind),
                'title' => $d->title, 'date' => $d->created_at,
                'source' => $d->occupancy ? self::url(\App\Filament\Alquileres\Resources\OccupancyResource::class, $d->occupancy) : null,
                'download' => null,   // prepared data for «Formularios»: the signed PDF appears as a signed document
            ])->all();
    }

    private static function envelopes(Party $party): array
    {
        try {
            $ids = DocumentParticipant::query()->where('party_id', (string) $party->getKey())->pluck('document_envelope_id');

            return DocumentEnvelope::query()->with('template')->where('team_id', (string) $party->team_id)->whereIn('id', $ids)->latest()->get()
                ->map(fn (DocumentEnvelope $e): array => [
                    'type' => __('documentos.generated.envelope'), 'title' => $e->document_number ?: ($e->template?->name ?? '#'.$e->getKey()),
                    'date' => $e->completed_at ?? $e->sent_at ?? $e->created_at, 'source' => null, 'download' => null,
                ])->all();
        } catch (Throwable) {
            return [];
        }
    }

    /** @return Collection<int, int> */
    private static function occupancyIds(Party $party): Collection
    {
        return OccupancyPerson::query()->where('party_id', $party->getKey())->pluck('occupancy_id');
    }

    public static function url(string $resource, Model $record): ?string
    {
        try {
            if (! class_exists($resource) || ! $resource::canAccess()) {
                return null;
            }
            foreach (['view', 'edit'] as $page) {
                if ($resource::hasPage($page)) {
                    return $resource::getUrl($page, ['record' => $record]);
                }
            }

            return $resource::getUrl('index');
        } catch (Throwable) {
            return null;
        }
    }
}
