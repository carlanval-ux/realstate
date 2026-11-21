<?php

declare(strict_types=1);

namespace App\Filament\Processes\Assistant;

use App\Processes\Assistant\AssistantQuery;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Facades\Filament;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * «Gestión de procesos» → Consultas sin respuesta útil: questions without documentation or rated as not useful, already
 * masked and WITHOUT who asked. Same access as the recommendations.
 */
final class QueriesTable extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    public function mount(): void
    {
        abort_unless(RecommendationsTable::allowed(), 403);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => AssistantQuery::query()->where('team_id', Filament::getTenant()?->getKey())->unanswered())
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label(__('asistente.consultas.fecha'))->dateTime('d/m/Y H:i', 'Europe/Madrid'),
                TextColumn::make('question')->label(__('asistente.pregunta'))->wrap()->searchable(),
                TextColumn::make('outcome')->label(__('asistente.consultas.resultado'))->badge()->formatStateUsing(fn (string $state): string => __('asistente.resultados.'.$state)),
                TextColumn::make('rating_comment')->label(__('asistente.valoracion.comentario'))->wrap()->placeholder('—'),
                TextColumn::make('improvement_request_id')->label(__('asistente.consultas.duda'))
                    ->formatStateUsing(fn ($state): string => $state ? __('asistente.consultas.enviada') : '—')->placeholder('—'),
            ])
            ->headerActions([
                Action::make('settings')->label(__('asistente.ajustes.titulo'))->icon('heroicon-m-cog-6-tooth')->color('gray')
                    ->url(fn (): string => AssistantSettingsPage::getUrl()),
            ])
            ->emptyStateHeading(__('asistente.consultas.vacio'));
    }

    public function render(): View
    {
        return view('filament.processes.assistant.table');
    }
}
