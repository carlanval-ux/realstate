<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\InboundMail\InboundMail;
use App\InboundMail\InboundMailAccess;
use App\InboundMail\InboundMailRule;
use App\InboundMail\InboundMails;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use Liberu\RealEstate\Parties\Models\Party;
use Livewire\Attributes\Url;
use Throwable;

/**
 * «Correo entrante»: what arrived at the company mailbox and where it went. Trays: «Pendiente de revisar» (staff
 * confirm or correct the suggestion; a correction becomes a sender/domain rule), «Entrada» (new enquiries by area,
 * suppliers, official notices: each with a TARMIO task), «Enrutados» (request replies, client timelines) and
 * «Archivo» (advertising and automatic messages). Bodies and attachments only with «correo.contenido».
 */
final class InboundMailInbox extends Page implements HasTable
{
    use InteractsWithTable;

    public const TRAYS = ['revisar' => ['revisar'], 'entrada' => ['entrada'], 'enrutado' => ['enrutado'], 'archivo' => ['archivado', 'atendido']];

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-envelope-open';

    protected static ?string $slug = 'correo-entrante';

    protected static ?int $navigationSort = 47;

    #[Url]
    public string $bandeja = 'revisar';

    public static function getNavigationLabel(): string
    {
        return __('correo.menu');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('filament.nav_groups.sales_lettings');
    }

    public function getTitle(): string
    {
        return __('correo.menu').' · '.__('correo.bandeja.'.$this->tray());
    }

    public static function canAccess(): bool
    {
        return InboundMailAccess::canView(auth()->user(), Filament::getTenant()?->getKey());
    }

    public static function getNavigationBadge(): ?string
    {
        $n = InboundMail::query()->where('team_id', Filament::getTenant()?->getKey())->whereIn('status', ['revisar', 'entrada'])->count();

        return $n > 0 ? (string) $n : null;
    }

    protected function getHeaderActions(): array
    {
        $teamId = Filament::getTenant()?->getKey();
        $actions = [];
        foreach (array_keys(self::TRAYS) as $tray) {
            $n = InboundMail::query()->where('team_id', $teamId)->whereIn('status', self::TRAYS[$tray])->count();
            $actions[] = Action::make('tray_'.$tray)->label(__('correo.bandeja.'.$tray).($tray === 'revisar' || $tray === 'entrada' ? " ({$n})" : ''))
                ->color($this->tray() === $tray ? 'primary' : 'gray')->url(self::getUrl(['bandeja' => $tray]));
        }
        $actions[] = Action::make('settings')->label(__('correo.ajustes.titulo'))->icon('heroicon-m-cog-6-tooth')->color('gray')->url(InboundMailSettingsPage::getUrl());

        return $actions;
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        $teamId = Filament::getTenant()?->getKey();
        $content = InboundMailAccess::canReadContent(auth()->user(), $teamId);
        $categories = collect(InboundMail::CATEGORIES)->mapWithKeys(fn (string $c) => [$c => __('correo.categoria.'.$c)])->all();
        $areas = collect(InboundMail::AREAS)->mapWithKeys(fn (string $a) => [$a => __('correo.area.'.$a)])->all();

        return $table
            ->query(InboundMail::query()->where('team_id', $teamId)->whereIn('status', self::TRAYS[$this->tray()]))
            ->defaultSort('id', 'desc')
            ->emptyStateHeading(__('correo.vacio'))
            ->columns([
                TextColumn::make('created_at')->label(__('correo.campo.recibido'))->dateTime('d/m/Y H:i', 'Europe/Madrid')->sortable(),
                TextColumn::make('from_email')->label(__('correo.campo.de'))->state(fn (InboundMail $record) => $record->from_name ?: $record->from_email)
                    ->description(fn (InboundMail $record) => $record->from_name ? $record->from_email : null)->searchable(['from_email', 'from_name']),
                TextColumn::make('subject')->label(__('correo.campo.asunto'))->limit(90)->wrap()->searchable()
                    ->description(fn (InboundMail $record) => $record->attachments ? '📎 '.count($record->attachments) : null),
                TextColumn::make('category')->label(__('correo.campo.categoria'))->badge()
                    ->state(fn (InboundMail $record) => $record->category ? $record->categoryLabel() : ($record->suggested_category ? __('correo.sugerido', ['c' => $record->categoryLabel($record->suggested_category)]) : '—'))
                    ->color(fn (InboundMail $record) => $record->category ? 'primary' : 'warning')
                    ->description(fn (InboundMail $record) => $record->areaLabel($record->area ?? $record->suggested_area)),
                TextColumn::make('method')->label(__('correo.campo.como'))
                    ->state(fn (InboundMail $record) => $record->method ? __('correo.metodo.'.$record->method).($record->confidence !== null ? ' · '.round($record->confidence * 100).' %' : '') : '—')
                    ->description(fn (InboundMail $record) => $record->reason ? __('correo.motivo.'.$record->reason) : ($record->route ? __('correo.ruta.'.$record->route) : null)),
            ])
            ->filters([
                SelectFilter::make('category')->label(__('correo.campo.categoria'))->options($categories),
                SelectFilter::make('area')->label(__('correo.campo.area'))->options($areas),
            ])
            ->recordActions([
                Action::make('view')->label(__('correo.accion.ver'))->icon('heroicon-m-eye')->color('gray')->visible($content)
                    ->modalHeading(fn (InboundMail $record) => (string) $record->subject ?: __('correo.sin_asunto'))
                    ->modalContent(fn (InboundMail $record) => self::preview($record))->modalSubmitAction(false)->modalCancelActionLabel(__('Close')),
                Action::make('confirm')->label(__('correo.accion.confirmar'))->icon('heroicon-m-check')->color('success')
                    ->visible(fn (InboundMail $record) => $record->status === 'revisar' && filled($record->suggested_category))
                    ->requiresConfirmation()->modalDescription(fn (InboundMail $record) => __('correo.confirmar_texto', ['c' => $record->categoryLabel($record->suggested_category)]))
                    ->action(fn (InboundMail $record) => self::attempt(fn () => InboundMails::confirm($record, auth()->user()))),
                Action::make('correct')->label(fn (InboundMail $record) => $record->status === 'revisar' ? __('correo.accion.clasificar') : __('correo.accion.reclasificar'))
                    ->icon('heroicon-m-pencil-square')->visible(fn (InboundMail $record) => $record->status !== 'enrutado')
                    ->fillForm(fn (InboundMail $record): array => ['category' => $record->suggested_category ?? $record->category, 'area' => $record->suggested_area ?? $record->area,
                        'party_id' => $record->party_id, 'rule' => InboundMailRule::SENDER])
                    ->schema([
                        Select::make('category')->label(__('correo.campo.categoria'))->options($categories)->required()->live(),
                        Select::make('area')->label(__('correo.campo.area'))->options($areas)->visible(fn (Get $get) => $get('category') === 'consulta')->required(fn (Get $get) => $get('category') === 'consulta'),
                        Select::make('party_id')->label(__('correo.campo.contacto'))->searchable()
                            ->visible(fn (Get $get) => in_array($get('category'), ['cliente', 'propietario'], true))
                            ->getSearchResultsUsing(fn (string $search) => Party::query()->forTeam($teamId)
                                ->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"))->limit(30)->pluck('name', 'id')->all())
                            ->getOptionLabelUsing(fn ($value) => Party::query()->forTeam($teamId)->whereKey($value)->value('name'))
                            ->helperText(__('correo.ayuda.contacto')),
                        Select::make('rule')->label(__('correo.campo.regla'))->placeholder(__('correo.regla.ninguna'))
                            ->options([InboundMailRule::SENDER => __('correo.regla.remitente'), InboundMailRule::DOMAIN => __('correo.regla.dominio')])
                            ->helperText(__('correo.ayuda.regla')),
                    ])
                    ->action(fn (InboundMail $record, array $data) => self::attempt(fn () => InboundMails::correct($record, $data['category'], $data['category'] === 'consulta' ? ($data['area'] ?? null) : null,
                        filled($data['party_id'] ?? null) ? (int) $data['party_id'] : null, $data['rule'] ?? null, auth()->user()))),
                Action::make('handled')->label(__('correo.accion.atendido'))->icon('heroicon-m-check-circle')->color('gray')
                    ->visible(fn (InboundMail $record) => $record->status === 'entrada')
                    ->action(fn (InboundMail $record) => InboundMails::markHandled($record, auth()->user())),
            ]);
    }

    private function tray(): string
    {
        return isset(self::TRAYS[$this->bandeja]) ? $this->bandeja : 'revisar';
    }

    private static function attempt(\Closure $do): void
    {
        try {
            $do();
            Notification::make()->title(__('correo.aviso.hecho'))->success()->send();
        } catch (Throwable $e) {
            report($e);
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    /** Body and attachments: text, and the sanitised HTML in a sandboxed frame (no scripts, no remote images). */
    private static function preview(InboundMail $mail): HtmlString
    {
        $e = fn (?string $s) => e((string) $s);
        $files = collect((array) $mail->attachments)->map(fn (array $a, int $i) => $a['path']
            ? '<a class="underline" href="'.$e(route('clarto.correo.adjunto', ['mail' => $mail->getKey(), 'index' => $i])).'">'.$e($a['name']).'</a> ('.number_format($a['size'] / 1024, 0, ',', '.').' KB)'
            : $e($a['name']).' — '.__('correo.adjunto_omitido'))->implode(' · ');
        $html = '<div class="text-sm space-y-3"><p><strong>'.__('correo.campo.de').':</strong> '.$e($mail->sender()).'<br><strong>'.__('correo.campo.para').':</strong> '.$e($mail->to)
            .'<br><strong>'.__('correo.campo.fecha').':</strong> '.$e($mail->sent_at?->timezone('Europe/Madrid')->format('d/m/Y H:i')).'</p>'
            .($files !== '' ? '<p><strong>'.__('correo.campo.adjuntos').':</strong> '.$files.'</p>' : '')
            .'<pre style="white-space:pre-wrap;font-family:inherit" class="rounded border p-3">'.$e($mail->text_body).'</pre>';
        if (filled($mail->html_body)) {
            $html .= '<details><summary class="cursor-pointer">'.__('correo.ver_html').'</summary><iframe sandbox="" referrerpolicy="no-referrer" style="width:100%;height:28rem;border:1px solid #ddd;background:#fff" srcdoc="'
                .$e('<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; img-src data:; style-src \'unsafe-inline\'">'.$mail->html_body).'"></iframe></details>';
        }

        return new HtmlString($html.'</div>');
    }
}
