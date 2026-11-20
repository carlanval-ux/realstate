<?php

declare(strict_types=1);

namespace App\Filament;

use Filament\Forms\Components\FileUpload;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs\Tab;
use Liberu\RealEstate\MediaAndDocuments\Models\MediaDocument;
use Liberu\RealEstate\Properties\Models\Property;

/**
 * "Photos and plans" tab of the property form: drag and drop, reorder and remove photos and floor plans.
 * Each file is a MediaDocument (kind photo / floorplan) of the property, in the order shown here; the first
 * photo is the cover on the website. Files live on the public disk; the website gets resized WebP copies.
 */
final class PropertyPhotos
{
    public static function tab(): Tab
    {
        return Tab::make(__('Photos and plans'))
            ->icon('heroicon-o-photo')
            ->schema([
                Section::make(__('Photos'))
                    ->description(__('Drag photos here or click to choose them. Drag to change the order: the first one is the cover on the website. JPG, PNG or WebP, up to 15 MB each.'))
                    ->schema([self::upload('clarto_photos', 'photo')]),
                Section::make(__('Floor plans'))
                    ->schema([self::upload('clarto_plans', 'floorplan')]),
            ]);
    }

    private static function upload(string $name, string $kind): FileUpload
    {
        return FileUpload::make($name)
            ->hiddenLabel()
            ->multiple()
            ->reorderable()
            ->appendFiles()
            ->image()
            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
            ->maxSize(15 * 1024)
            ->maxFiles(60)
            ->disk('public')
            ->directory('inmuebles')
            ->visibility('public')
            ->panelLayout('grid')
            ->imagePreviewHeight('140')
            ->dehydrated(false)
            ->afterStateHydrated(function (FileUpload $component, ?Property $record) use ($kind): void {
                $component->state($record
                    ? MediaDocument::query()->where('property_id', $record->getKey())->where('kind', $kind)->orderBy('sort_order')->orderBy('id')->pluck('path')->all()
                    : []);
            })
            ->saveRelationshipsUsing(function (FileUpload $component, Property $record) use ($kind): void {
                $paths = array_values(array_filter((array) $component->getState()));

                // Removed in the form → removed from the property (the file stays until the storage is cleaned).
                MediaDocument::query()->where('property_id', $record->getKey())->where('kind', $kind)->whereNotIn('path', $paths)->delete();

                foreach ($paths as $i => $path) {
                    $doc = MediaDocument::query()->firstOrNew(['property_id' => $record->getKey(), 'kind' => $kind, 'path' => $path]);
                    $doc->forceFill([
                        'team_id' => $record->team_id,
                        'created_by' => $doc->created_by ?? auth()->id() ?? $record->created_by,
                        'title' => $doc->title ?: ($kind === 'photo' ? __('Photo :n', ['n' => $i + 1]) : __('Floor plan :n', ['n' => $i + 1])),
                        'sort_order' => $i + 1,
                    ])->save();
                }
            });
    }
}
