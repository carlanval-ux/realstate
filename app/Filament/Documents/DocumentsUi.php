<?php

declare(strict_types=1);

namespace App\Filament\Documents;

use App\Documents\ClientFiles;
use App\Filament\Owners\OwnerTabs;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Model;
use Liberu\RealEstate\MediaAndDocuments\Models\MediaDocument;
use Liberu\RealEstate\Parties\Models\Party;
use Liberu\RealEstate\Properties\Models\Property;
use Liberu\RealEstate\PropertiesFilament\Resources\PropertyResource;

/**
 * Files live on the records, not in a global list: property «Documents» tab, and on every client record (owner 360,
 * contacts) their own documents, what other features generated for them and the properties they are linked to.
 */
final class DocumentsUi
{
    /** «Documents» tab of the property form (hidden while creating: there is no record yet). */
    public static function propertyTab(): Tab
    {
        return Tab::make(__('documentos.tab'))->icon('heroicon-o-paper-clip')
            ->visible(fn (?Model $record): bool => $record?->exists === true)
            ->schema([
                self::files('property'),
                Section::make(__('documentos.sections.legacy'))->description(__('documentos.sections.legacy_help'))->collapsible()
                    ->visible(fn (?Model $record): bool => $record !== null && self::legacy($record)->isNotEmpty())
                    ->schema([Html::make(fn (?Model $record): string => OwnerTabs::table(
                        ['documentos.fields.title', 'documentos.fields.category', 'documentos.fields.uploaded'],
                        self::legacy($record)->map(fn (MediaDocument $d): array => [[$d->title ?: basename((string) $d->path), $d->publicUrl()], (string) $d->kind, $d->created_at?->timezone('Europe/Madrid')->format('d/m/Y') ?? '—'])->all(),
                    ))]),
            ]);
    }

    /** «Documents» tab of the owner 360 page. */
    public static function clientTab(): Tab
    {
        return Tab::make(__('documentos.tab'))->icon('heroicon-o-paper-clip')->schema(self::clientSchema());
    }

    /** «Documents» section below a contact's form. A page's content schema has no record: pass it. */
    public static function clientSection(?Model $for = null): Section
    {
        return Section::make(__('documentos.tab'))->icon('heroicon-o-paper-clip')->collapsible()->collapsed()
            ->visible(fn (?Model $record): bool => ($for ?? $record) instanceof Party)
            ->schema(self::clientSchema($for));
    }

    /** @return list<\Filament\Schemas\Components\Component> */
    private static function clientSchema(?Model $for = null): array
    {
        $party = fn (?Model $record): ?Party => ($p = $for ?? $record) instanceof Party ? $p : null;

        return [
            Section::make(__('documentos.sections.own'))->description(__('documentos.sections.own_help'))->schema([self::files('party', $for)]),
            Section::make(__('documentos.sections.generated'))->description(__('documentos.sections.generated_help'))->collapsible()
                ->schema([Html::make(fn (?Model $record): string => ($p = $party($record)) ? OwnerTabs::table(
                    ['documentos.fields.type', 'documentos.fields.title', 'documentos.fields.date', 'documentos.fields.file'],
                    array_map(fn (array $row): array => [
                        $row['type'], [$row['title'], $row['source']], $row['date']?->timezone('Europe/Madrid')->format('d/m/Y') ?? '—',
                        $row['download'] ? [__('documentos.download'), $row['download']] : '—',
                    ], ClientFiles::generated($p)),
                ) : '')]),
            Section::make(__('documentos.sections.properties'))->collapsible()
                ->schema([Html::make(function (?Model $record) use ($party): string {
                    $p = $party($record);
                    if ($p === null) {
                        return '';
                    }
                    $links = ClientFiles::linkedProperties($p);
                    $rows = Property::query()->where('team_id', $p->team_id)->whereIn('id', array_keys($links))->get()->map(fn (Property $property): array => [
                        [$property->reference(), ClientFiles::url(PropertyResource::class, $property)],
                        (string) $property->address,
                        implode(' · ', array_map(fn (string $r): string => __('documentos.relations.'.$r), $links[$property->getKey()] ?? [])),
                    ])->all();

                    return OwnerTabs::table(['documentos.fields.reference', 'documentos.fields.address', 'documentos.fields.relation'], $rows);
                })]),
        ];
    }

    private static function files(string $owner, ?Model $for = null): Livewire
    {
        return Livewire::make(RecordFiles::class, fn (?Model $record): array => ['owner' => $owner, 'ownerId' => (int) ($for ?? $record)?->getKey()])
            ->key(fn (?Model $record): string => "documentos-{$owner}-".($for ?? $record)?->getKey())
            ->visible(fn (?Model $record): bool => ($for ?? $record)?->exists === true);
    }

    /** Documents filed in the old global list (module media documents that are not photos, plans or videos). */
    private static function legacy(Model $property): \Illuminate\Support\Collection
    {
        return MediaDocument::query()->where('team_id', $property->team_id)->where('property_id', $property->getKey())
            ->whereNotIn('kind', [...array_keys(MediaDocument::GALLERY_KINDS), 'video'])->latest()->get();
    }
}
