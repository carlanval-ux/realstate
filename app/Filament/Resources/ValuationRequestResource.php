<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\ValuationRequestResource\Pages\ManageValuationRequests;
use App\Modules\ClartoModules;
use App\Valuations\GhlValuationSync;
use App\Valuations\ProcessValuation;
use App\Valuations\ReportLinks;
use App\Valuations\ValuationDelivery;
use App\Valuations\ValuationEngine;
use App\Valuations\ValuationRequest;
use App\Valuations\ValuationRun;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;

/**
 * «Valoraciones automáticas»: requests from the website with their reports, errors and history. Staff allowed by
 * ValuationAccess see their team's requests; «Recalcular» adds a run (history kept) and «Reintentar GHL» resyncs.
 */
final class ValuationRequestResource extends Resource
{
    protected static ?string $model = ValuationRequest::class;

    protected static ?string $slug = 'valoraciones';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-calculator';

    protected static ?int $navigationSort = 40;

    protected static ?string $recordTitleAttribute = 'reference';

    public static function isScopedToTenant(): bool
    {
        return false;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('filament.nav_groups.people_relationships');
    }

    public static function getNavigationLabel(): string
    {
        return __('clarto.valoraciones.menu');
    }

    public static function getModelLabel(): string
    {
        return __('clarto.valoraciones.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('clarto.valoraciones.plural');
    }

    public static function canViewAny(): bool
    {
        return auth()->check();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with('currentRun')->visibleTo(auth()->user());
        $tenant = Filament::getTenant();

        return $tenant === null ? $query : $query->where('team_id', $tenant->getKey());
    }

    /**
     * Recalculate, send, links, GHL. Never on an anonymised request (Retention): nobody to send to, nothing to sync. Checked
     * here and not in the gate, because Shield's Gate::before lets a super administrator through every gate.
     */
    public static function canManage(ValuationRequest $record): bool
    {
        return $record->anonymized_at === null && Gate::allows('manageValuation', $record);
    }

    public static function table(Table $table): Table
    {
        $eur = fn ($v): string => $v === null ? '—' : number_format((float) $v, 0, ',', '.').' €';

        return $table
            ->columns([
                TextColumn::make('reference')->label(__('clarto.valoraciones.campos.referencia'))->searchable()->sortable(),
                TextColumn::make('created_at')->label(__('clarto.valoraciones.campos.fecha'))->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('direccion')->label(__('clarto.valoraciones.campos.direccion'))->state(fn (ValuationRequest $record): string => $record->address())->limit(45)->wrap(),
                TextColumn::make('contact.nombre')->label(__('clarto.valoraciones.campos.contacto')),
                TextColumn::make('status')->label(__('clarto.valoraciones.campos.estado'))->badge()
                    ->formatStateUsing(fn (string $state): string => __('clarto.valoraciones.estados.'.$state))->color(fn (string $state): string => self::color($state)),
                TextColumn::make('currentRun.result.precio_central')->label(__('clarto.valoraciones.campos.precio'))->formatStateUsing(fn ($state): string => $eur($state)),
                IconColumn::make('currentRun.simulated')->label(__('clarto.valoraciones.campos.simulado'))->boolean(),
                TextColumn::make('envio_email')->label(__('clarto.valoraciones.envio.correo'))->badge()
                    ->state(fn (ValuationRequest $record): string => ValuationDelivery::state($record)['email']['estado'])
                    ->formatStateUsing(fn (string $state): string => __('clarto.valoraciones.envio.estados.'.$state))->color(fn (string $state): string => self::deliveryColor($state)),
                TextColumn::make('envio_whatsapp')->label(__('clarto.valoraciones.envio.whatsapp'))->badge()
                    ->state(fn (ValuationRequest $record): string => ValuationDelivery::state($record)['whatsapp']['estado'])
                    ->formatStateUsing(fn (string $state): string => __('clarto.valoraciones.envio.estados.'.$state))->color(fn (string $state): string => self::deliveryColor($state)),
                TextColumn::make('ghl_status')->label('GHL')->badge()->formatStateUsing(fn (string $state): string => __('clarto.valoraciones.ghl.'.$state))
                    ->color(fn (string $state): string => ['sincronizada' => 'success', 'error' => 'danger', 'sin_configurar' => 'warning'][$state] ?? 'gray'),
            ])
            ->filters([SelectFilter::make('status')->label(__('clarto.valoraciones.campos.estado'))->options(fn (): array => collect(['recibida', 'estimada', 'revision', 'error'])->mapWithKeys(fn ($s) => [$s => __('clarto.valoraciones.estados.'.$s)])->all())])
            ->recordActions([
                ViewAction::make(),
                Action::make('informe')->label(__('clarto.valoraciones.acciones.informe'))->icon('heroicon-o-document-arrow-down')->color('gray')
                    ->visible(fn (ValuationRequest $record): bool => $record->currentRun?->pdf_path !== null)
                    ->url(fn (ValuationRequest $record): string => route('clarto.valoraciones.informe', ['valuationRequest' => $record->getKey()]), shouldOpenInNewTab: true),
                Action::make('recalcular')->label(__('clarto.valoraciones.acciones.recalcular'))->icon('heroicon-o-arrow-path')
                    ->visible(fn (ValuationRequest $record): bool => self::canManage($record) && ClartoModules::enabled('valoraciones'))
                    ->requiresConfirmation()->modalDescription(__('clarto.valoraciones.acciones.recalcular_ayuda'))
                    ->action(function (ValuationRequest $record): void {
                        abort_unless(self::canManage($record), 403);
                        $run = app(ValuationEngine::class)->run($record, 'recalculo', auth()->id());
                        app(ValuationEngine::class)->improveSummary($run);
                        (new GhlValuationSync)->sync($record->fresh());
                        Notification::make()->title(__('clarto.valoraciones.acciones.recalculada', ['n' => $run->number, 'estado' => __('clarto.valoraciones.estados.'.$run->status)]))
                            ->{$run->status === 'error' ? 'danger' : 'success'}()->send();
                    }),
                Action::make('reenviar')->label(__('clarto.valoraciones.envio.reenviar'))->icon('heroicon-o-paper-airplane')->color('gray')
                    ->visible(fn (ValuationRequest $record): bool => self::canManage($record) && ValuationDelivery::pending($record))
                    ->requiresConfirmation()->modalDescription(__('clarto.valoraciones.envio.reenviar_ayuda'))
                    ->action(function (ValuationRequest $record): void {
                        abort_unless(self::canManage($record), 403);
                        ProcessValuation::dispatch($record->getKey());
                        Notification::make()->title(__('clarto.valoraciones.envio.en_cola'))->success()->send();
                    }),
                Action::make('enviar_actual')->label(__('clarto.valoraciones.envio.enviar_actual'))->icon('heroicon-o-envelope')->color('gray')
                    ->visible(fn (ValuationRequest $record): bool => self::canManage($record) && $record->currentRun !== null && ValuationDelivery::state($record)['run'] !== $record->currentRun->number)
                    ->requiresConfirmation()->modalDescription(__('clarto.valoraciones.envio.enviar_actual_ayuda'))
                    ->action(function (ValuationRequest $record): void {
                        abort_unless(self::canManage($record), 403);
                        ProcessValuation::dispatch($record->getKey(), resend: true);
                        Notification::make()->title(__('clarto.valoraciones.envio.en_cola'))->success()->send();
                    }),
                // Public report links (ReportLinks): a new one revokes the previous ones; shown once to copy, sent if asked.
                Action::make('enlace_nuevo')->label('Nuevo enlace del informe')->icon('heroicon-o-link')->color('gray')
                    ->visible(fn (ValuationRequest $record): bool => self::canManage($record) && $record->currentRun?->pdf_path !== null)
                    ->modalDescription('Desactiva los enlaces anteriores de esta solicitud (también los firmados antiguos) y crea uno nuevo, válido '.ReportLinks::days().' días. Se actualiza en GoHighLevel.')
                    ->schema([\Filament\Forms\Components\Toggle::make('enviar')->label('Enviarlo también al cliente (correo y WhatsApp)')->default(false)])
                    ->action(function (ValuationRequest $record, array $data): void {
                        abort_unless(self::canManage($record), 403);
                        $issued = ReportLinks::regenerate($record, auth()->id());
                        if ($issued === null) {
                            Notification::make()->title('Esta solicitud no tiene informe PDF.')->danger()->send();

                            return;
                        }
                        // Same process: GHL and the messages share the code just issued (ReportLinks keeps it in memory).
                        (new GhlValuationSync)->sync($record->fresh());
                        if ($data['enviar'] ?? false) {
                            ValuationDelivery::markForResend($record->fresh());
                            ProcessValuation::dispatchSync($record->getKey());
                        }
                        Notification::make()->title('Enlace nuevo, válido hasta el '.$issued['expires_at']->copy()->timezone('Europe/Madrid')->format('d/m/Y'))
                            ->body($issued['url'].' — se muestra solo ahora: cópialo si vas a enviarlo a mano.')->persistent()->success()->send();
                    }),
                Action::make('enlaces_revocar')->label('Revocar enlaces del informe')->icon('heroicon-o-no-symbol')->color('danger')
                    ->visible(fn (ValuationRequest $record): bool => self::canManage($record) && $record->currentRun?->pdf_path !== null)
                    ->requiresConfirmation()->modalDescription('Los enlaces enviados (cortos y firmados antiguos) dejarán de funcionar y se vacía el enlace en GoHighLevel. Nada se volverá a enviar con enlace hasta crear uno nuevo.')
                    ->action(function (ValuationRequest $record): void {
                        abort_unless(self::canManage($record), 403);
                        $n = ReportLinks::revoke($record, auth()->id());
                        (new GhlValuationSync)->sync($record->fresh());
                        Notification::make()->title("Enlaces revocados ({$n} activos).")->success()->send();
                    }),
                Action::make('ghl')->label(__('clarto.valoraciones.acciones.reintentar_ghl'))->icon('heroicon-o-arrow-up-tray')->color('gray')
                    ->visible(fn (ValuationRequest $record): bool => self::canManage($record) && $record->ghl_status !== 'sincronizada')
                    ->action(function (ValuationRequest $record): void {
                        abort_unless(self::canManage($record), 403);
                        (new GhlValuationSync)->sync($record);
                        $record->refresh();
                        Notification::make()->title(__('clarto.valoraciones.ghl.'.$record->ghl_status))->body($record->ghl_error)
                            ->{$record->ghl_status === 'sincronizada' ? 'success' : 'warning'}()->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function infolist(Schema $schema): Schema
    {
        $eur = fn ($v): string => $v === null ? '—' : number_format((float) $v, 0, ',', '.').' €';

        return $schema->components([
            Section::make(__('clarto.valoraciones.secciones.solicitud'))->columns(3)->schema([
                TextEntry::make('reference')->label(__('clarto.valoraciones.campos.referencia')),
                TextEntry::make('status')->label(__('clarto.valoraciones.campos.estado'))->badge()
                    ->formatStateUsing(fn (string $state): string => __('clarto.valoraciones.estados.'.$state))->color(fn (string $state): string => self::color($state)),
                TextEntry::make('created_at')->label(__('clarto.valoraciones.campos.fecha'))->dateTime('d/m/Y H:i'),
                TextEntry::make('direccion')->label(__('clarto.valoraciones.campos.direccion'))->state(fn (ValuationRequest $record): string => $record->address())->columnSpan(2),
                TextEntry::make('catastro.rc')->label(__('clarto.valoraciones.campos.rc'))->placeholder(fn (ValuationRequest $record): string => (string) ($record->catastro['error'] ?? '—')),
                TextEntry::make('contacto')->label(__('clarto.valoraciones.campos.contacto'))->columnSpanFull()
                    ->state(fn (ValuationRequest $record): string => implode(' · ', array_filter([$record->contact['nombre'] ?? null, $record->contact['email'] ?? null, $record->contact['telefono'] ?? null, $record->contact['canal'] ?? null, $record->contact['franja'] ?? null]))
                        ?: ($record->anonymized_at !== null ? 'Datos personales eliminados el '.$record->anonymized_at->timezone('Europe/Madrid')->format('d/m/Y').' (plazo de conservación)' : '')),
                TextEntry::make('datos')->label(__('clarto.valoraciones.campos.datos'))->columnSpanFull()
                    ->state(fn (ValuationRequest $record): string => collect((array) $record->property)->filter(fn ($v) => $v !== null && $v !== '')->map(fn ($v, $k) => $k.': '.(is_bool($v) ? ($v ? 'sí' : 'no') : (is_array($v) ? json_encode($v) : $v)))->implode(' · ')),
                TextEntry::make('last_error')->label(__('clarto.valoraciones.campos.error'))->columnSpanFull()->color('danger')->visible(fn (ValuationRequest $record): bool => filled($record->last_error)),
            ]),
            Section::make(__('clarto.valoraciones.envio.titulo'))->columns(3)->schema([
                TextEntry::make('envio_correo')->label(__('clarto.valoraciones.envio.correo'))
                    ->state(fn (ValuationRequest $record): string => self::deliveryLine(ValuationDelivery::state($record)['email'])),
                TextEntry::make('envio_whatsapp')->label(__('clarto.valoraciones.envio.whatsapp'))
                    ->state(fn (ValuationRequest $record): string => self::deliveryLine(ValuationDelivery::state($record)['whatsapp'])),
                TextEntry::make('envio_calculo')->label(__('clarto.valoraciones.envio.calculo'))->state(fn (ValuationRequest $record): string => (string) (ValuationDelivery::state($record)['run'] ?? '—')),
                TextEntry::make('envio_historial')->label(__('clarto.valoraciones.envio.historial'))->columnSpanFull()
                    ->state(fn (ValuationRequest $record): string => collect(ValuationDelivery::state($record)['historial'])->map(fn (array $h): string => substr((string) $h['fecha'], 0, 16).' · '.$h['canal'].' · '.__('clarto.valoraciones.envio.estados.'.$h['estado']).' · n.º '.$h['calculo'].($h['error'] ? ' · '.$h['error'] : ''))->implode("\n") ?: '—')
                    ->extraAttributes(['style' => 'white-space: pre-line']),
            ]),
            Section::make('Enlaces del informe')->columns(1)->schema([
                TextEntry::make('enlaces')->hiddenLabel()->extraAttributes(['style' => 'white-space: pre-line'])
                    ->state(fn (ValuationRequest $record): string => $record->reportLinks()->with('run')->limit(20)->get()
                        ->map(fn (\App\Valuations\ReportLink $l): string => '#'.$l->getKey().' · cálculo n.º '.$l->run?->number.' · '.$l->state().' · creado '.$l->created_at?->timezone('Europe/Madrid')->format('d/m/Y H:i')
                            .' ('.$l->origin.') · caduca '.$l->expires_at->timezone('Europe/Madrid')->format('d/m/Y').' · aperturas '.$l->opens
                            .($l->ghl_at ? ' · en GHL' : '').($l->revoked_at ? ' · revocado '.$l->revoked_at->timezone('Europe/Madrid')->format('d/m/Y H:i') : '')
                            .' · huella '.substr($l->code_hash, 0, 8))
                        ->implode("\n") ?: 'Sin enlaces cortos todavía.'),
            ]),
            Section::make('GoHighLevel')->columns(3)->schema([
                TextEntry::make('ghl_status')->label(__('clarto.valoraciones.campos.estado'))->formatStateUsing(fn (string $state): string => __('clarto.valoraciones.ghl.'.$state)),
                TextEntry::make('ghl_opportunity_id')->label(__('clarto.valoraciones.campos.oportunidad'))->placeholder('—'),
                TextEntry::make('ghl_synced_at')->label(__('clarto.valoraciones.campos.sincronizada'))->dateTime('d/m/Y H:i')->placeholder('—'),
                TextEntry::make('ghl_error')->label(__('clarto.valoraciones.campos.error'))->columnSpanFull()->color('danger')->visible(fn (ValuationRequest $record): bool => filled($record->ghl_error)),
                // WhatsApp nurture of this contact (B.3 v2, sent by Liberu): plan and every planned message with its state.
                TextEntry::make('nutricion_whatsapp')->label('Nutrición por WhatsApp')->columnSpanFull()->extraAttributes(['style' => 'white-space: pre-line'])
                    ->state(fn (ValuationRequest $record): string => \App\Acquisition\NurturePlan::query()->where('contact_id', (string) $record->ghl_contact_id)->latest('id')->limit(3)->get()
                        ->map(fn ($plan): string => 'Plan '.$plan->rama.' · '.$plan->estado.($plan->motivo ? ' ('.$plan->motivo.')' : '').' · desde '.$plan->started_at?->format('d/m/Y')."\n"
                            .$plan->slots->map(fn ($s): string => '   '.strtoupper(str_replace('_', '-', $s->codigo)).' · '.$s->send_after->timezone('Europe/Madrid')->format('d/m H:i').' · '.$s->estado.($s->motivo ? ' · '.$s->motivo : ''))->implode("\n"))
                        ->implode("\n") ?: '—'),
            ]),
            RepeatableEntry::make('runs')->label(__('clarto.valoraciones.secciones.historial'))->columnSpanFull()->schema([
                TextEntry::make('resumen')->hiddenLabel()->html()->state(function (ValuationRun $record) use ($eur): HtmlString {
                    $r = (array) $record->result;
                    $line = e('#'.$record->number.' · '.$record->created_at?->format('d/m/Y H:i').' · '.__('clarto.valoraciones.estados.'.$record->status)
                        .' · '.($record->provider ?? '—').($record->simulated ? ' (SIMULADO)' : '').' · v'.$record->version.' · '.__('clarto.valoraciones.disparadores.'.$record->trigger));
                    if ($record->status === 'estimada') {
                        $line .= ' · <strong>'.e($eur($r['precio_central'] ?? null)).'</strong> ('.e($eur($r['intervalo']['min'] ?? null)).' – '.e($eur($r['intervalo']['max'] ?? null)).', '.e((string) ($r['suficiencia'] ?? '')).')'
                            .' · '.e(__('clarto.valoraciones.campos.resumen').': '.$record->summary_source.' · '.($record->pdf_pages ?? '—').' pág.');
                    }
                    $link = $record->pdf_path ? ' · <a class="underline" target="_blank" href="'.e(route('clarto.valoraciones.informe', ['valuationRequest' => $record->valuation_request_id, 'run' => $record->number])).'">PDF</a>' : '';
                    $errors = collect((array) $record->provider_errors)->map(fn (array $e): string => e($e['nombre'].': '.$e['error']))->implode('<br>');

                    return new HtmlString($line.$link.($record->message ? '<br>'.e($record->message) : '').($errors ? '<br><span style="color:#b91c1c">'.$errors.'</span>' : ''));
                }),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageValuationRequests::route('/')];
    }

    private static function deliveryColor(string $status): string
    {
        return ['enviado' => 'success', 'error' => 'danger', 'linea_no_vinculada' => 'warning'][$status] ?? 'gray';
    }

    /** @param array<string, mixed> $channel */
    private static function deliveryLine(array $channel): string
    {
        return __('clarto.valoraciones.envio.estados.'.$channel['estado']).(filled($channel['fecha'] ?? null) ? ' · '.substr((string) $channel['fecha'], 0, 16) : '')
            .(($channel['intentos'] ?? 0) > 1 ? ' · '.$channel['intentos'].' intentos' : '').(filled($channel['error'] ?? null) ? ' · '.$channel['error'] : '');
    }

    private static function color(string $status): string
    {
        return ['estimada' => 'success', 'revision' => 'warning', 'error' => 'danger'][$status] ?? 'gray';
    }
}
