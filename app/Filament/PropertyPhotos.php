<?php

declare(strict_types=1);

namespace App\Filament;

use Closure;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Liberu\RealEstate\MediaAndDocuments\Models\MediaDocument;
use Liberu\RealEstate\Properties\Models\Property;

/**
 * "Photos and videos" tab of the property form: drag and drop, reorder and remove photos, floor plans and videos,
 * plus YouTube/Vimeo links. Each item is a MediaDocument of the property (kind photo / floorplan / video) in the
 * order shown here; the first photo is the cover on the website. Uploads live on the public disk (the website gets
 * resized WebP copies of photos); a video link is a `video` row whose path is the URL.
 */
final class PropertyPhotos
{
    public const VIDEO_TYPES = ['video/mp4', 'video/webm', 'video/quicktime'];

    public const VIDEO_MAX_KB = 60 * 1024;

    public const VIDEO_URL = '#^https://(www\.|m\.)?(youtube\.com/(watch\?v=|shorts/|embed/)|youtu\.be/|vimeo\.com/|player\.vimeo\.com/video/)[\w\-?=&/.%]+$#';

    public static function tab(): Tab
    {
        $uploaded = fn (Builder $query): Builder => $query->where('path', 'not like', 'http%');

        return Tab::make(__('documentos.photos_tab'))
            ->icon('heroicon-o-photo')
            ->schema([
                Section::make(__('Photos'))
                    ->description(__('Drag photos here or click to choose them. Drag to change the order: the first one is the cover on the website. JPG, PNG or WebP, up to 15 MB each.'))
                    ->schema([self::upload('clarto_photos', 'photo', ['image/jpeg', 'image/png', 'image/webp'], 15 * 1024, 60)]),
                Section::make(__('Floor plans'))
                    ->schema([self::upload('clarto_plans', 'floorplan', ['image/jpeg', 'image/png', 'image/webp'], 15 * 1024, 60)]),
                Section::make(__('documentos.videos'))
                    ->description(__('documentos.videos_help'))
                    ->schema([
                        self::upload('clarto_videos', 'video', self::VIDEO_TYPES, self::VIDEO_MAX_KB, 10, $uploaded),
                        self::videoLinks(),
                    ]),
            ]);
    }

    /** YouTube / Vimeo links, in order (kind video, path = URL). */
    private static function videoLinks(): Repeater
    {
        $links = fn (Builder $query): Builder => $query->where('path', 'like', 'http%');

        return Repeater::make('clarto_video_links')->label(__('documentos.video_links'))
            ->simple(TextInput::make('url')->url()->regex(self::VIDEO_URL)->maxLength(500)->required()
                ->validationMessages(['regex' => __('documentos.video_link_invalid')]))
            ->reorderable()->defaultItems(0)->maxItems(20)->addActionLabel(__('documentos.add_video_link'))
            ->dehydrated(false)
            ->afterStateHydrated(function (Repeater $component, ?Property $record) use ($links): void {
                $component->rawState($record ? self::query($record, 'video', $links)->pluck('path')->all() : []);
                $component->hydrateItems();   // this hook replaces the repeater's own, which keys the items
            })
            ->saveRelationshipsUsing(function (Repeater $component, Property $record) use ($links): void {
                $urls = array_values(array_unique(array_filter(array_map(fn ($item): ?string => is_array($item) ? ($item['url'] ?? null) : $item, (array) $component->getState()))));
                self::sync($record, 'video', $urls, $links);
            });
    }

    /** @param list<string> $types */
    private static function upload(string $name, string $kind, array $types, int $maxKb, int $maxFiles, ?Closure $scope = null): FileUpload
    {
        $upload = FileUpload::make($name)
            ->hiddenLabel()
            ->multiple()
            ->reorderable()
            ->appendFiles()
            ->acceptedFileTypes($types)
            ->maxSize($maxKb)
            ->maxFiles($maxFiles)
            ->disk('public')
            ->directory('inmuebles')
            ->visibility('public')
            ->panelLayout('grid')
            ->dehydrated(false)
            ->afterStateHydrated(function (FileUpload $component, ?Property $record) use ($kind, $scope): void {
                $component->state($record ? self::query($record, $kind, $scope)->pluck('path')->all() : []);
            })
            ->saveRelationshipsUsing(function (FileUpload $component, Property $record) use ($kind, $scope): void {
                self::sync($record, $kind, array_values(array_filter((array) $component->getState())), $scope);
            });

        return $kind === 'video' ? $upload : $upload->image()->imagePreviewHeight('140');
    }

    private static function query(Property $record, string $kind, ?Closure $scope): Builder
    {
        return MediaDocument::query()->where('property_id', $record->getKey())->where('kind', $kind)
            ->when($scope, $scope)->orderBy('sort_order')->orderBy('id');
    }

    /** @param list<string> $paths in display order */
    private static function sync(Property $record, string $kind, array $paths, ?Closure $scope): void
    {
        // Removed in the form → removed from the property (an uploaded file stays until the storage is cleaned).
        self::query($record, $kind, $scope)->whereNotIn('path', $paths)->delete();

        $titles = ['photo' => 'Photo :n', 'floorplan' => 'Floor plan :n', 'video' => 'documentos.video_n'];
        foreach ($paths as $i => $path) {
            $doc = MediaDocument::query()->firstOrNew(['property_id' => $record->getKey(), 'kind' => $kind, 'path' => $path]);
            $doc->forceFill([
                'team_id' => $record->team_id,
                'created_by' => $doc->created_by ?? auth()->id() ?? $record->created_by,
                'title' => $doc->title ?: __($titles[$kind], ['n' => $i + 1]),
                'sort_order' => $i + 1,
            ])->save();
        }
    }
}
