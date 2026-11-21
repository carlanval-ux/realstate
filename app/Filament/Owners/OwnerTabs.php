<?php

declare(strict_types=1);

namespace App\Filament\Owners;

use App\Acquisition\GhlHandoff;
use App\Owners\OwnerProfile;
use App\Owners\OwnerProfileSections;
use App\Owners\SpanishIds;
use BackedEnum;
use Carbon\CarbonInterface;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Liberu\RealEstate\Instructions\Models\Instruction;
use Liberu\RealEstate\MediaAndDocuments\Models\DocumentEnvelope;
use Liberu\RealEstate\MediaAndDocuments\Models\DocumentParticipant;
use Liberu\RealEstate\MediaAndDocuments\Models\MediaDocument;
use Liberu\RealEstate\Parties\Models\Party;
use Liberu\RealEstate\Properties\Models\Property;
use Liberu\RealEstate\Valuations\Models\Valuation;
use Throwable;


/**
 * The owner 360 page, laid out like the CRM001 customer record: Resumen · Datos y contactos · Direcciones ·
 * Facturación · Inmuebles · Encargos · Valoraciones · Documentos · Historial, with the tabs other features
 * register in App\Owners\OwnerProfileSections placed by their sort. Lists are read-only and link to each record.
 */
final class OwnerTabs
{
    /** Sort of the built-in tabs (registered tabs interleave by their own sort; Historial stays last). */
    public const SORTS = ['summary' => 10, 'contact' => 20, 'addresses' => 30, 'billing' => 40, 'properties' => 50,
        'mandates' => 55, 'valuations' => 58, 'documents' => 60, 'history' => 90];

    public static function schema(Schema $schema): Schema
    {
        $tabs = [
            [self::SORTS['summary'], fn (): Tab => self::summary()],
            [self::SORTS['contact'], fn (): Tab => self::contactData()],
            [self::SORTS['addresses'], fn (): Tab => self::addresses()],
            [self::SORTS['billing'], fn (): Tab => self::billing()],
            [self::SORTS['properties'], fn (): Tab => self::properties()],
            [self::SORTS['mandates'], fn (): Tab => self::mandates()],
            [self::SORTS['valuations'], fn (): Tab => self::valuations()],
            [self::SORTS['documents'], fn (): Tab => self::documents()],
            [self::SORTS['history'], fn (): Tab => self::history()],
        ];
        foreach (OwnerProfileSections::all() as $section) {
            $tabs[] = [$section['sort'], $section['tab']];
        }
        usort($tabs, fn (array $a, array $b): int => $a[0] <=> $b[0]);   // stable: built-ins first on equal sort

        return $schema->columns(1)->components([
            Tabs::make('owner360')->persistTabInQueryString()->tabs(array_map(fn (array $t): Tab => ($t[1])(), $tabs)),
        ]);
    }

    private static function profile(Party $record): OwnerProfile
    {
        return $record->clartoOwnerProfile ?? new OwnerProfile(['person_type' => 'fisica']);
    }

    private static function entry(string $name, string $label, \Closure $state): TextEntry
    {
        return TextEntry::make($name)->label(__($label))->state($state)->placeholder('—');
    }

    private static function company(Party $record): bool
    {
        return self::profile($record)->person_type === 'juridica';
    }

    private static function summary(): Tab
    {
        $p = fn (Party $record): OwnerProfile => self::profile($record);

        return Tab::make(__('propietarios.tabs.summary'))->icon('heroicon-o-user-circle')->schema([
            Text::make(function (Party $record) use ($p): string {
                $missing = $p($record)->missingFields($record);

                return $missing === []
                    ? __('propietarios.complete_long')
                    : __('propietarios.missing_list', ['fields' => implode(' · ', array_map(fn (string $f): string => __('propietarios.fields.'.$f), $missing))]);
            })->color(fn (Party $record): string => $p($record)->missingFields($record) === [] ? 'success' : 'warning')->weight('medium'),
            Section::make()->columns(4)->schema([
                self::entry('s_code', 'propietarios.fields.code', fn (Party $record) => $p($record)->code()),
                self::entry('s_name', 'propietarios.fields.display_name', fn (Party $record) => $record->name),
                self::entry('s_type', 'propietarios.fields.person_type', fn (Party $record) => __(OwnerProfile::PERSON_TYPES[$p($record)->person_type ?? 'fisica'] ?? '—')),
                self::entry('s_tax_id', 'propietarios.fields.tax_id', fn (Party $record) => $p($record)->displayTaxId()),
                self::entry('s_email', 'propietarios.fields.email', fn (Party $record) => $record->email)->copyable(),
                self::entry('s_phone', 'propietarios.fields.phone', fn (Party $record) => $record->phone),
                self::entry('s_channel', 'propietarios.fields.preferred_channel', fn (Party $record) => ($c = $p($record)->preferred_channel) ? __('propietarios.channels.'.$c) : null),
                self::entry('s_language', 'propietarios.fields.preferred_language', fn (Party $record) => ($l = $p($record)->preferred_language) ? __('propietarios.languages.'.$l) : null),
                self::entry('s_properties', 'propietarios.tabs.properties', fn (Party $record) => (string) OwnerProfile::propertyIds($record)->count()),
                self::entry('s_mandates', 'propietarios.fields.active_mandates', fn (Party $record) => (string) Instruction::query()->where('party_id', $record->getKey())->whereIn('status', OwnerProfile::ACTIVE_MANDATE)->count()),
                self::entry('s_marketing', 'propietarios.fields.marketing_consent_at', fn (Party $record) => $p($record)->marketing_consent_at ? __('propietarios.yes') : __('propietarios.no')),
                self::entry('s_tags', 'propietarios.fields.tags', fn (Party $record) => $p($record)->tags ?: null)->badge(),
            ]),
        ]);
    }

    private static function contactData(): Tab
    {
        $p = fn (Party $record): OwnerProfile => self::profile($record);

        return Tab::make(__('propietarios.tabs.contact'))->icon('heroicon-o-identification')->schema([
            Section::make(__('propietarios.sections.identity'))->columns(3)->schema([
                self::entry('d_type', 'propietarios.fields.person_type', fn (Party $record) => __(OwnerProfile::PERSON_TYPES[$p($record)->person_type ?? 'fisica'] ?? '—')),
                self::entry('d_tax_id_type', 'propietarios.fields.tax_id_type', fn (Party $record) => ($t = $p($record)->tax_id_type) ? __('propietarios.id_types.'.$t) : null),
                self::entry('d_tax_id', 'propietarios.fields.tax_id', fn (Party $record) => $p($record)->displayTaxId()),
                self::entry('d_first_name', 'propietarios.fields.first_name', fn (Party $record) => $p($record)->first_name)->visible(fn (Party $record): bool => ! self::company($record)),
                self::entry('d_last_names', 'propietarios.fields.last_names', fn (Party $record) => trim($p($record)->last_name.' '.$p($record)->second_last_name) ?: null)->visible(fn (Party $record): bool => ! self::company($record)),
                self::entry('d_birth', 'propietarios.fields.birth_date', fn (Party $record) => $p($record)->birth_date?->format('d/m/Y'))->visible(fn (Party $record): bool => ! self::company($record)),
                self::entry('d_nationality', 'propietarios.fields.nationality', fn (Party $record) => $p($record)->nationality)->visible(fn (Party $record): bool => ! self::company($record)),
                self::entry('d_legal_name', 'propietarios.fields.legal_name', fn (Party $record) => $p($record)->legal_name)->visible(fn (Party $record): bool => self::company($record)),
                self::entry('d_trading_name', 'propietarios.fields.trading_name', fn (Party $record) => $p($record)->trading_name)->visible(fn (Party $record): bool => self::company($record)),
                self::entry('d_representative', 'propietarios.fields.representative_name', fn (Party $record) => trim($p($record)->representative_name.' '.(OwnerProfile::canSeeSensitive() ? $p($record)->representative_tax_id : SpanishIds::maskTaxId($p($record)->representative_tax_id))) ?: null)
                    ->visible(fn (Party $record): bool => self::company($record)),
            ]),
            Section::make(__('propietarios.sections.contact_means'))->columns(3)->schema([
                self::entry('d_email', 'propietarios.fields.email', fn (Party $record) => $record->email)->copyable(),
                self::entry('d_phone', 'propietarios.fields.phone', fn (Party $record) => $record->phone),
                self::entry('d_phone2', 'propietarios.fields.phone_secondary', fn (Party $record) => $p($record)->phone_secondary),
                self::entry('d_channel', 'propietarios.fields.preferred_channel', fn (Party $record) => ($c = $p($record)->preferred_channel) ? __('propietarios.channels.'.$c) : null),
                self::entry('d_language', 'propietarios.fields.preferred_language', fn (Party $record) => ($l = $p($record)->preferred_language) ? __('propietarios.languages.'.$l) : null),
                self::entry('d_window', 'propietarios.fields.contact_window', fn (Party $record) => $p($record)->contact_window),
            ]),
            Section::make(__('propietarios.sections.consents'))->columns(3)->schema([
                self::entry('d_privacy', 'propietarios.fields.privacy_consent_at', fn (Party $record) => self::consent($p($record)->privacy_consent_at, $p($record)->privacy_consent_version)),
                self::entry('d_marketing', 'propietarios.fields.marketing_consent_at', fn (Party $record) => self::consent($p($record)->marketing_consent_at, $p($record)->marketing_consent_version)
                    .(($ch = $p($record)->marketing_consent_channel) ? ' · '.__('propietarios.consent_channels.'.$ch) : '') ?: null),
                self::entry('d_consent_source', 'propietarios.fields.consent_source', fn (Party $record) => ($s = $p($record)->consent_source) ? __('propietarios.consent_sources.'.$s) : null),
                self::entry('d_evidence', 'propietarios.fields.consent_evidence', fn (Party $record) => $p($record)->consent_evidence)->columnSpanFull(),
            ]),
            Section::make(__('propietarios.sections.notes'))->columns(3)->schema([
                self::entry('d_tags', 'propietarios.fields.tags', fn (Party $record) => $p($record)->tags ?: null)->badge(),
                self::entry('d_ghl', 'propietarios.fields.ghl_contact', fn (Party $record) => implode(' · ', array_filter([$record->metadata['ghl_contact_id'] ?? null, ...(array) ($record->metadata['ghl_contact_ids'] ?? [])])) ?: null),
                self::entry('d_source', 'propietarios.fields.source', fn (Party $record) => __('propietarios.sources.'.($p($record)->source ?? 'panel'))),
                self::entry('d_notes', 'propietarios.fields.notes', fn (Party $record) => $p($record)->notes)->columnSpanFull(),
            ]),
        ]);
    }

    private static function addresses(): Tab
    {
        $p = fn (Party $record): OwnerProfile => self::profile($record);

        return Tab::make(__('propietarios.tabs.addresses'))->icon('heroicon-o-map-pin')->schema([
            Section::make()->columns(2)->schema([
                self::entry('a_fiscal', 'propietarios.sections.fiscal_address', fn (Party $record) => self::address($p($record), 'fiscal')),
                self::entry('a_postal', 'propietarios.sections.postal_address', fn (Party $record) => $p($record)->postal_same_as_fiscal ?? true ? __('propietarios.fields.postal_same_as_fiscal') : self::address($p($record), 'postal')),
            ]),
        ]);
    }

    private static function billing(): Tab
    {
        $p = fn (Party $record): OwnerProfile => self::profile($record);

        return Tab::make(__('propietarios.tabs.billing'))->icon('heroicon-o-banknotes')->schema([
            Section::make(__('propietarios.sections.bank'))->columns(3)->schema([
                self::entry('b_method', 'propietarios.fields.payment_method', fn (Party $record) => ($m = $p($record)->payment_method) ? __('propietarios.payment_methods.'.$m) : null),
                self::entry('b_holder', 'propietarios.fields.iban_holder', fn (Party $record) => $p($record)->iban_holder),
                self::entry('b_iban', 'IBAN', fn (Party $record) => $p($record)->displayIban()),
                self::entry('b_bic', 'BIC', fn (Party $record) => $p($record)->bic),
                self::entry('b_tax_address', 'propietarios.sections.fiscal_address', fn (Party $record) => self::address($p($record), 'fiscal'))->columnSpan(2),
            ]),
        ]);
    }

    private static function properties(): Tab
    {
        return Tab::make(__('propietarios.tabs.properties'))->icon('heroicon-o-home')
            ->badge(fn (Party $record): int => OwnerProfile::propertyIds($record)->count())
            ->schema([Html::make(function (Party $record): string {
                $rows = Property::query()->whereIn('id', OwnerProfile::propertyIds($record))->latest()->get()->map(fn (Property $p): array => [
                    [$p->reference(), self::url('Liberu\\RealEstate\\PropertiesFilament\\Resources\\PropertyResource', $p)],
                    $p->address,
                    __('clarto.property_statuses.'.self::value($p->status)),
                    self::date($p->created_at),
                ])->all();

                return self::table(['propietarios.cols.reference', 'propietarios.cols.address', 'propietarios.cols.status', 'propietarios.cols.created'], $rows);
            })]);
    }

    private static function mandates(): Tab
    {
        return Tab::make(__('propietarios.tabs.mandates'))->icon('heroicon-o-document-check')
            ->badge(fn (Party $record): ?int => Instruction::query()->where('party_id', $record->getKey())->whereIn('status', OwnerProfile::ACTIVE_MANDATE)->count() ?: null)
            ->schema([Html::make(function (Party $record): string {
                $refs = self::references(Instruction::query()->where('party_id', $record->getKey())->pluck('property_id'));
                $rows = Instruction::query()->where('party_id', $record->getKey())->latest()->get()->map(fn (Instruction $i): array => [
                    [$i->subject, self::url('Liberu\\RealEstate\\InstructionsFilament\\Resources\\InstructionResource', $i)],
                    $refs[(int) $i->property_id] ?? '—',
                    __('propietarios.instruction_statuses.'.self::value($i->status)),
                    self::date($i->created_at),
                    self::date($i->approved_at),
                ])->all();

                return self::table(['propietarios.cols.subject', 'propietarios.cols.property', 'propietarios.cols.status', 'propietarios.cols.created', 'propietarios.cols.approved'], $rows);
            })]);
    }

    private static function valuations(): Tab
    {
        return Tab::make(__('propietarios.tabs.valuations'))->icon('heroicon-o-calculator')
            ->schema([Html::make(function (Party $record): string {
                $valuations = Valuation::query()->where('party_id', $record->getKey())->latest()->get();
                $refs = self::references($valuations->pluck('property_id'));
                $rows = $valuations->map(fn (Valuation $v): array => [
                    [$v->subject, self::url('Liberu\\RealEstate\\ValuationsFilament\\Resources\\ValuationResource', $v)],
                    $refs[(int) $v->property_id] ?? '—',
                    __('propietarios.valuation_statuses.'.self::value($v->status)),
                    $v->valued_amount !== null ? number_format((float) $v->valued_amount, 0, ',', '.').' '.($v->currency ?: '€') : '—',
                    self::date($v->completed_at ?? $v->created_at),
                ])->all();

                return self::table(['propietarios.cols.subject', 'propietarios.cols.property', 'propietarios.cols.status', 'propietarios.cols.amount', 'propietarios.cols.date'], $rows);
            })]);
    }

    private static function documents(): Tab
    {
        return Tab::make(__('propietarios.tabs.documents'))->icon('heroicon-o-paper-clip')
            ->schema([
                Section::make(__('propietarios.sections.files'))->schema([Html::make(function (Party $record): string {
                    $ids = OwnerProfile::propertyIds($record);
                    $refs = self::references($ids);
                    $rows = MediaDocument::query()->whereIn('property_id', $ids)->whereNotIn('kind', array_keys(MediaDocument::GALLERY_KINDS))->latest()->get()
                        ->map(fn (MediaDocument $d): array => [
                            [$d->title ?: basename((string) $d->path), self::url('Liberu\\RealEstate\\MediaAndDocumentsFilament\\Resources\\MediaDocumentResource', $d)],
                            $d->kind,
                            $refs[(int) $d->property_id] ?? '—',
                            self::date($d->created_at),
                        ])->all();

                    return self::table(['propietarios.cols.title', 'propietarios.cols.kind', 'propietarios.cols.property', 'propietarios.cols.created'], $rows);
                })]),
                Section::make(__('propietarios.sections.envelopes'))->schema([Html::make(function (Party $record): string {
                    $ids = OwnerProfile::propertyIds($record);
                    $refs = self::references($ids);
                    $envelopeIds = DocumentParticipant::query()->where('party_id', (string) $record->getKey())->pluck('document_envelope_id');
                    $rows = DocumentEnvelope::query()->with('template')
                        ->where(fn ($q) => $q->whereIn('property_id', $ids->map(fn (int $id): string => (string) $id))->orWhereIn('id', $envelopeIds))
                        ->latest()->get()
                        ->map(fn (DocumentEnvelope $e): array => [
                            $e->document_number ?: ($e->template?->name ?? '#'.$e->getKey()),
                            $refs[(int) $e->property_id] ?? '—',
                            __('propietarios.envelope_statuses.'.self::value($e->status)),
                            self::date($e->sent_at),
                            self::date($e->completed_at),
                        ])->all();

                    return self::table(['propietarios.cols.document', 'propietarios.cols.property', 'propietarios.cols.status', 'propietarios.cols.sent', 'propietarios.cols.signed'], $rows);
                })]),
            ]);
    }

    private static function history(): Tab
    {
        return Tab::make(__('propietarios.tabs.history'))->icon('heroicon-o-clock')
            ->schema([Html::make(function (Party $record): string {
                $events = [[$record->created_at, __('propietarios.events.owner_since')]];
                $handoffs = GhlHandoff::query()->where('party_id', $record->getKey())->get();
                $refs = self::references($handoffs->pluck('property_id'));
                foreach ($handoffs as $h) {
                    $events[] = [$h->processed_at ?? $h->created_at, __('propietarios.events.ghl_won', ['opportunity' => $h->opportunity_id, 'property' => $refs[(int) $h->property_id] ?? '—'])
                        .($h->deliveries > 1 ? ' · '.__('propietarios.events.deliveries', ['count' => $h->deliveries]) : '')];
                }
                foreach (Instruction::query()->where('party_id', $record->getKey())->get() as $i) {
                    $events[] = [$i->created_at, __('propietarios.events.mandate_created', ['subject' => $i->subject])];
                    $events[] = [$i->approved_at, __('propietarios.events.mandate_approved', ['subject' => $i->subject])];
                    $events[] = [$i->withdrawn_at, __('propietarios.events.mandate_withdrawn', ['subject' => $i->subject])];
                }
                foreach (Valuation::query()->where('party_id', $record->getKey())->get() as $v) {
                    $events[] = [$v->created_at, __('propietarios.events.valuation_created', ['subject' => $v->subject])];
                    $events[] = [$v->completed_at, __('propietarios.events.valuation_completed', ['subject' => $v->subject])];
                }
                foreach (self::activity($record) as $a) {
                    $events[] = [Carbon::parse($a->created_at), self::describe($a)];
                }

                $rows = collect($events)->filter(fn (array $e): bool => $e[0] instanceof CarbonInterface)
                    ->sortByDesc(fn (array $e): int => $e[0]->getTimestamp())
                    ->map(fn (array $e): array => [$e[0]->timezone('Europe/Madrid')->format('d/m/Y H:i'), $e[1]])->values()->all();

                return self::table(['propietarios.cols.date', 'propietarios.cols.event'], $rows);
            })]);
    }

    /** @return list<object> activity_log rows about this owner (profile changes, linking, other modules' events) */
    private static function activity(Party $record): array
    {
        try {
            return DB::table('activity_log')->where('subject_type', $record->getMorphClass())->where('subject_id', $record->getKey())
                ->leftJoin('users', 'users.id', '=', 'activity_log.causer_id')
                ->orderByDesc('activity_log.id')->limit(200)
                ->get(['activity_log.log_name', 'activity_log.event', 'activity_log.description', 'activity_log.properties', 'activity_log.created_at', 'users.name as causer'])->all();
        } catch (Throwable) {
            return [];
        }
    }

    private static function describe(object $a): string
    {
        $properties = (array) json_decode((string) $a->properties, true);
        $text = match ($a->log_name === 'propietarios' ? $a->event : null) {
            'profile_updated' => __('propietarios.events.profile_updated', ['fields' => implode(', ', array_map(
                fn (string $f): string => ($t = __('propietarios.fields.'.$f)) !== 'propietarios.fields.'.$f ? $t : $f,
                (array) ($properties['fields'] ?? []),
            ))]),
            'ghl_linked' => __(($properties['new_owner'] ?? false) ? 'propietarios.events.ghl_linked_new' : 'propietarios.events.ghl_linked', ['opportunity' => $properties['opportunity_id'] ?? '—']),
            'owner_created' => __('propietarios.events.owner_created'),
            default => (string) $a->description,
        };

        return $text.($a->causer ? ' · '.$a->causer : '');
    }

    /**
     * @param  \Illuminate\Support\Collection<int, mixed>  $propertyIds
     * @return array<int, string> property id → reference
     */
    private static function references(\Illuminate\Support\Collection $propertyIds): array
    {
        return Property::query()->whereIn('id', $propertyIds->filter()->unique())->get()
            ->mapWithKeys(fn (Property $p): array => [(int) $p->getKey() => $p->reference()])->all();
    }

    private static function url(string $resource, Model $record): ?string
    {
        try {
            foreach (['view', 'edit'] as $page) {
                if (class_exists($resource) && $resource::hasPage($page) && $resource::canAccess()) {
                    return $resource::getUrl($page, ['record' => $record]);
                }
            }
        } catch (Throwable) {
        }

        return null;
    }

    private static function value(mixed $value): string
    {
        return $value instanceof BackedEnum ? (string) $value->value : (string) $value;
    }

    private static function date(mixed $date): string
    {
        return $date instanceof CarbonInterface ? $date->timezone('Europe/Madrid')->format('d/m/Y') : '—';
    }

    private static function consent(?CarbonInterface $at, ?string $version): ?string
    {
        return $at === null ? null : __('propietarios.consent_given', ['date' => $at->timezone('Europe/Madrid')->format('d/m/Y H:i'), 'version' => $version ?: '—']);
    }

    private static function address(OwnerProfile $p, string $prefix): ?string
    {
        $line = implode(', ', array_filter([
            $p->{$prefix.'_address'},
            trim($p->{$prefix.'_postal_code'}.' '.$p->{$prefix.'_city'}),
            $p->{$prefix.'_province'},
            $p->{$prefix.'_country'} !== 'ES' ? $p->{$prefix.'_country'} : null,
        ]));

        return $line !== '' ? $line : null;
    }

    /**
     * Read-only HTML table. A cell is a string, or [text, url] for a link.
     *
     * @param  list<string>  $headers  translation keys
     * @param  list<list<string|array{0: string, 1: ?string}>>  $rows
     */
    private static function table(array $headers, array $rows): string
    {
        if ($rows === []) {
            return '<p style="opacity:.7">'.e(__('propietarios.empty')).'</p>';
        }
        $th = implode('', array_map(fn (string $h): string => '<th style="text-align:left;padding:.4rem .6rem;font-weight:600;border-bottom:1px solid rgba(128,128,128,.3)">'.e(__($h)).'</th>', $headers));
        $tr = implode('', array_map(fn (array $row): string => '<tr>'.implode('', array_map(function (string|array $cell): string {
            [$text, $url] = is_array($cell) ? $cell : [$cell, null];
            $text = e((string) $text);

            return '<td style="padding:.4rem .6rem;border-bottom:1px solid rgba(128,128,128,.15);vertical-align:top">'
                .($url ? '<a href="'.e($url).'" style="color:var(--primary-600);text-decoration:underline">'.$text.'</a>' : $text).'</td>';
        }, $row)).'</tr>', $rows));

        return '<div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;font-size:.875rem"><thead><tr>'.$th.'</tr></thead><tbody>'.$tr.'</tbody></table></div>';
    }
}
