<?php

declare(strict_types=1);

namespace App\Filament\Processes\Assistant;

use App\Models\User;
use App\Processes\Assistant\AssistantSettings;
use App\Processes\ProcessAccess;
use App\Processes\ProcessLinks;
use App\Processes\Recommendations\Recommendation;
use App\Processes\Recommendations\Recommendations;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Throwable;

/** «Gestión de procesos» → Recomendaciones (only procesos.administrar / company administrators). */
final class RecommendationsTable extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    public static function allowed(): bool
    {
        $user = auth()->user();
        $team = Filament::getTenant()?->getKey();

        return $user instanceof User && $team !== null && ProcessAccess::canAdminister($user, $team);
    }

    public function mount(): void
    {
        abort_unless(self::allowed(), 403);
    }

    public function table(Table $table): Table
    {
        $team = fn (): mixed => Filament::getTenant()?->getKey();

        return $table
            ->query(fn () => Recommendation::query()->where('team_id', $team()))
            ->defaultSort(fn ($query) => $query->orderBy('priority')->orderByDesc('id'))
            ->columns([
                TextColumn::make('priority')->label(__('asistente.recomendaciones.prioridad'))->badge()
                    ->formatStateUsing(fn (int $state): string => __('asistente.prioridades.'.$state))
                    ->color(fn (int $state): string => [1 => 'danger', 2 => 'warning'][$state] ?? 'gray')->sortable(),
                TextColumn::make('type')->label(__('asistente.recomendaciones.tipo'))->formatStateUsing(fn (string $state): string => __('asistente.tipos.'.$state)),
                TextColumn::make('title')->label(__('asistente.recomendaciones.recomendacion'))->wrap()->searchable()
                    ->description(fn (Recommendation $record): string => $record->reason),
                TextColumn::make('owner')->label(__('asistente.recomendaciones.responsable'))->wrap()
                    ->state(fn (Recommendation $record): string => implode(' · ', array_filter([
                        $record->suggested_position_id ? DB::table('clarto_positions')->where('id', $record->suggested_position_id)->value('name') : null,
                        $record->suggested_user_id ? User::query()->whereKey($record->suggested_user_id)->value('name') : null,
                    ])) ?: '—'),
                TextColumn::make('ai_label')->label(__('asistente.recomendaciones.ia'))->badge()->color('info')
                    ->state(fn (Recommendation $record): ?string => $record->ai_generated ? __('asistente.recomendaciones.propuesta_ia') : null),
                TextColumn::make('status')->label(__('asistente.recomendaciones.estado'))->badge()
                    ->formatStateUsing(fn (string $state): string => __('asistente.estados.'.$state))
                    ->description(fn (Recommendation $record): ?string => $record->decision_reason),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('asistente.recomendaciones.estado'))->default('pendiente')
                    ->options(collect(Recommendation::STATUSES)->mapWithKeys(fn (string $s): array => [$s => __('asistente.estados.'.$s)])->all()),
                SelectFilter::make('type')->label(__('asistente.recomendaciones.tipo'))
                    ->options(collect(Recommendation::TYPES)->mapWithKeys(fn (string $s): array => [$s => __('asistente.tipos.'.$s)])->all()),
            ])
            ->headerActions([
                Action::make('generate')->label(__('asistente.recomendaciones.actualizar'))->icon('heroicon-m-arrow-path')
                    ->action(function () use ($team): void {
                        abort_unless(self::allowed(), 403);
                        $n = Recommendations::generate($team());
                        Notification::make()->title(__('asistente.recomendaciones.actualizadas', ['n' => $n]))->success()->send();
                    }),
            ])
            ->recordActions([
                Action::make('body')->label(__('asistente.recomendaciones.ver_propuesta'))->icon('heroicon-m-document-text')->color('gray')
                    ->visible(fn (Recommendation $record): bool => filled($record->body))
                    ->modalHeading(fn (Recommendation $record): string => $record->ai_generated ? __('asistente.recomendaciones.propuesta_ia') : __('asistente.recomendaciones.propuesta'))
                    ->schema(fn (Recommendation $record): array => [Text::make($record->body)])->modalSubmitAction(false),
                Action::make('propose')->label(__('asistente.recomendaciones.proponer'))->icon('heroicon-m-sparkles')->color('gray')
                    ->visible(fn (Recommendation $record): bool => in_array($record->status, ['pendiente', 'aceptada'], true) && blank($record->body) && AssistantSettings::activeFor($record->team_id))
                    ->requiresConfirmation()->modalDescription(__('asistente.recomendaciones.proponer_ayuda'))
                    ->action(fn (Recommendation $record) => $this->run(fn (User $u) => Recommendations::propose($u, $record), __('asistente.recomendaciones.propuesta_lista'))),
                Action::make('accept')->label(__('asistente.recomendaciones.aceptar'))->icon('heroicon-m-check')->color('success')
                    ->visible(fn (Recommendation $record): bool => $record->status === 'pendiente')
                    ->action(fn (Recommendation $record) => $this->run(fn (User $u) => Recommendations::accept($u, $record), __('asistente.recomendaciones.aceptada'))),
                Action::make('draft')->label(__('asistente.recomendaciones.abrir_borrador'))->icon('heroicon-m-pencil-square')->color('primary')
                    ->visible(fn (Recommendation $record): bool => in_array($record->status, ['pendiente', 'aceptada'], true) && $record->type !== 'sector')
                    ->modalHeading(__('asistente.recomendaciones.abrir_borrador'))
                    ->schema(function (Recommendation $record): array {
                        $similar = $record->process_id ? [] : Recommendations::similar($record->team_id, $record->map_entry_id ? (string) DB::table('clarto_process_map_entries')->where('id', $record->map_entry_id)->value('title') : $record->title);

                        return $similar === [] ? [Text::make(__('asistente.recomendaciones.sin_similares'))] : [
                            Text::make(__('asistente.recomendaciones.similares', ['lista' => collect($similar)->map(fn (array $s): string => "{$s['code']} · {$s['title']} (".(int) round($s['score'] * 100).' %)')->implode('; ')])),
                            Checkbox::make('reviewed')->label(__('asistente.recomendaciones.revisados'))->accepted(),
                        ];
                    })
                    ->action(function (Recommendation $record, array $data): void {
                        $draft = $this->run(fn (User $u) => Recommendations::openDraft($u, $record, (bool) ($data['reviewed'] ?? false)), __('asistente.recomendaciones.borrador_abierto'));
                        if ($draft !== null) {
                            $this->redirect(ProcessLinks::version($draft));
                        }
                    }),
                Action::make('dismiss')->label(__('asistente.recomendaciones.descartar'))->icon('heroicon-m-x-mark')->color('danger')
                    ->visible(fn (Recommendation $record): bool => in_array($record->status, ['pendiente', 'aceptada'], true))
                    ->schema([Textarea::make('reason')->label(__('asistente.recomendaciones.motivo_descarte'))->required()->minLength(3)->maxLength(1000)])
                    ->action(fn (Recommendation $record, array $data) => $this->run(fn (User $u) => Recommendations::dismiss($u, $record, (string) $data['reason']), __('asistente.recomendaciones.descartada'))),
            ])
            ->emptyStateHeading(__('asistente.recomendaciones.vacio'));
    }

    public function render(): View
    {
        return view('filament.processes.assistant.table');
    }

    /** Runs a recommendation operation as the signed-in person; reports the outcome. */
    private function run(\Closure $operation, string $done): mixed
    {
        abort_unless(self::allowed(), 403);
        try {
            $result = $operation(auth()->user());
        } catch (Throwable $e) {
            report($e);
            Notification::make()->title(__('asistente.errores.operacion'))->body($e->getMessage())->danger()->send();

            return null;
        }
        Notification::make()->title($done)->success()->send();

        return $result;
    }
}
