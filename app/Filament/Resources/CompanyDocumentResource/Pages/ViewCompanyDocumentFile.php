<?php

declare(strict_types=1);

namespace App\Filament\Resources\CompanyDocumentResource\Pages;

use App\Documents\CompanyDocument;
use App\Documents\CompanyDocumentPreview;
use App\Filament\Resources\CompanyDocumentResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

/**
 * The web viewer of an expediente's files (…/documentacion-empresa/{record}/visor/archivo-0 or anotacion-12): the file
 * inside the panel — PDF in a frame, image as it is, Word / Excel / OpenDocument as the PDF converted on this server —
 * with «Anterior» / «Siguiente» through every file of the expediente (then its annotations' attachments),
 * «Descargar» and back to the expediente. What cannot be shown says «Vista previa no disponible» and offers the
 * download. Same permission as the record (CompanyDocumentPolicy::view).
 */
final class ViewCompanyDocumentFile extends Page
{
    use InteractsWithRecord;

    protected static string $resource = CompanyDocumentResource::class;

    public string $file = '';

    /** @var array<string, array{path: string, name: string, mime: string, size: int, label: string, download: string, preview: string}>|null */
    private ?array $items = null;

    private ?bool $converted = null;

    public function mount(int|string $record, string $file): void
    {
        $this->record = $this->resolveRecord($record);
        abort_unless(self::getResource()::canView($this->getRecord()), 403);
        $this->file = $file;
        abort_unless($this->current() !== null, 404);
    }

    public function getTitle(): string
    {
        return (string) ($this->current()['label'] ?? __('documentacion.viewer.title'));
    }

    public function getSubheading(): string
    {
        $keys = array_keys($this->items());

        return __('documentacion.viewer.position', [
            'title' => $this->document()->title,
            'position' => (int) array_search($this->file, $keys, true) + 1,
            'total' => count($keys),
        ]);
    }

    /** @return array<string, string> */
    public function getBreadcrumbs(): array
    {
        return [
            CompanyDocumentResource::getUrl() => CompanyDocumentResource::getPluralModelLabel(),
            CompanyDocumentResource::getUrl('view', ['record' => $this->document()]) => $this->document()->title,
            __('documentacion.viewer.title'),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([Html::make(fn (): HtmlString => $this->frame())]);
    }

    /** The neighbour in the viewer's order (-1 previous, +1 next), or null at either end. */
    public function neighbour(int $step): ?string
    {
        $keys = array_keys($this->items());
        $at = array_search($this->file, $keys, true);

        return $at === false ? null : ($keys[$at + $step] ?? null);
    }

    protected function getHeaderActions(): array
    {
        $to = fn (?string $key): ?string => $key === null ? null : CompanyDocumentResource::getUrl('visor', ['record' => $this->document(), 'file' => $key]);

        return [
            Action::make('previous')->label(__('documentacion.viewer.previous'))->icon('heroicon-o-chevron-left')->color('gray')
                ->url(fn (): ?string => $to($this->neighbour(-1)))->visible(fn (): bool => $this->neighbour(-1) !== null),
            Action::make('next')->label(__('documentacion.viewer.next'))->icon('heroicon-o-chevron-right')->iconPosition('after')->color('gray')
                ->url(fn (): ?string => $to($this->neighbour(1)))->visible(fn (): bool => $this->neighbour(1) !== null),
            Action::make('download')->label(__('documentacion.download'))->icon('heroicon-o-arrow-down-tray')
                ->url(fn (): string => (string) ($this->current()['download'] ?? '')),
            Action::make('back')->label(__('documentacion.viewer.back'))->icon('heroicon-o-arrow-uturn-left')->color('gray')
                ->url(fn (): string => CompanyDocumentResource::getUrl('view', ['record' => $this->document()])),
        ];
    }

    private function document(): CompanyDocument
    {
        $record = $this->getRecord();
        abort_unless($record instanceof CompanyDocument, 404);

        return $record;
    }

    /** @return array<string, array{path: string, name: string, mime: string, size: int, label: string, download: string, preview: string}> */
    private function items(): array
    {
        return $this->items ??= CompanyDocumentPreview::items($this->document());
    }

    /** @return array{path: string, name: string, mime: string, size: int, label: string, download: string, preview: string}|null */
    private function current(): ?array
    {
        return $this->items()[$this->file] ?? null;
    }

    /** Word / Excel / OpenDocument: converted (or found in the cache) before the frame asks for it. */
    private function converted(): bool
    {
        $file = $this->current();

        return $this->converted ??= $file !== null && app(CompanyDocumentPreview::class)->pdfFor($this->document(), $file) !== null;
    }

    private function frame(): HtmlString
    {
        $file = $this->current();
        if ($file === null) {
            return new HtmlString('');
        }
        $kind = CompanyDocumentPreview::kind($file['mime']);
        $src = e($file['preview']);
        $label = e($file['label']);

        if ($kind === 'image') {
            return new HtmlString('<div style="display:flex;justify-content:center;padding:.5rem;border:1px solid rgba(127,127,127,.25);border-radius:.75rem">'
                .'<img src="'.$src.'" alt="'.$label.'" style="max-width:100%;max-height:calc(100vh - 15rem);object-fit:contain"></div>');
        }
        if ($kind === 'pdf' || ($kind === 'office' && $this->converted())) {
            return new HtmlString(($kind === 'office' ? '<p style="font-size:.875rem;opacity:.7;margin-bottom:.5rem">'.e(__('documentacion.viewer.converted')).'</p>' : '')
                .'<iframe src="'.$src.'" title="'.$label.'" style="width:100%;height:calc(100vh - 15rem);min-height:30rem;border:1px solid rgba(127,127,127,.25);border-radius:.75rem;background:#fff"></iframe>'
                .'<p style="font-size:.8rem;opacity:.65;margin-top:.5rem">'.e(__('documentacion.viewer.not_showing')).' '
                .'<a href="'.$src.'" target="_blank" rel="noopener" style="text-decoration:underline">'.e(__('documentacion.viewer.new_tab')).'</a></p>');
        }

        return new HtmlString('<div style="text-align:center;padding:3rem 1rem;border:1px dashed rgba(127,127,127,.4);border-radius:.75rem">'
            .'<p style="font-size:1.125rem;font-weight:600;margin-bottom:.5rem">'.e(__('documentacion.viewer.unavailable')).'</p>'
            .'<p style="opacity:.75;margin-bottom:1.25rem">'.e(__('documentacion.viewer.unavailable_help', ['name' => $file['name']])).'</p>'
            .'<a href="'.e($file['download']).'" style="display:inline-block;padding:.5rem 1rem;border-radius:.5rem;background:var(--primary-600, #0d9488);color:#fff;font-weight:600">'
            .e(__('documentacion.download')).'</a></div>');
    }
}
