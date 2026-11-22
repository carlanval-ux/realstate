<?php

declare(strict_types=1);

namespace App\Filament\Collaborators;

use App\Collaborators\Collaborations;
use App\Collaborators\CollaboratorNotice;
use App\Collaborators\CollaboratorNotices;
use App\Collaborators\CollaboratorProperty;
use App\Collaborators\PropertyUpdate;
use App\Collaborators\UpdateMessage;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Storage;
use Liberu\RealEstate\Properties\Models\Property;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Follow-up of one property (staff): internal notes (never sent) and shared updates (e-mail + WhatsApp to the
 * property's collaborators, also in their panel). Offer, reservation and closing wait for «Revisar y enviar».
 */
final class PropertyUpdates extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    public const MAX_KB = 10240;

    public const MIMES = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];

    #[Locked]
    public int $propertyId;

    public function mount(int $propertyId): void
    {
        $this->propertyId = $propertyId;
        $this->property();
    }

    public function property(): Property
    {
        abort_unless(CollaboratorUi::staff(), 403);

        return Property::query()->where('team_id', CollaboratorUi::teamId())->findOrFail($this->propertyId);
    }

    #[On('clarto-colaboradores-cambio')]
    public function refreshUpdates(): void
    {
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        $property = $this->property();
        $f = fn (string $k): string => __('colaboradores.fields.'.$k);

        return $table
            ->query(PropertyUpdate::query()->with(['author', 'notices'])->where('team_id', $property->team_id)->where('property_id', $property->getKey()))
            ->defaultSort('id', 'desc')
            ->paginated([10, 25, 50])
            ->emptyStateHeading(__('colaboradores.panel.no_updates'))
            ->emptyStateIcon('heroicon-o-chat-bubble-left-right')
            ->columns([
                TextColumn::make('created_at')->label($f('date'))->dateTime('d/m/Y H:i', 'Europe/Madrid')->description(fn (PropertyUpdate $record): ?string => $record->author?->name),
                TextColumn::make('kind')->label($f('kind_update'))->formatStateUsing(CollaboratorUi::label('update_kinds'))
                    ->description(fn (PropertyUpdate $record): ?string => $record->title),
                TextColumn::make('status')->label($f('visibility'))->badge()->formatStateUsing(CollaboratorUi::label('update_statuses'))
                    ->color(fn (string $state): string => ['registrada' => 'gray', 'pendiente_revision' => 'warning', 'compartida' => 'success'][$state] ?? 'danger'),
                TextColumn::make('body')->label($f('body'))->wrap()->limit(160)->placeholder('—')
                    ->state(fn (PropertyUpdate $record): ?string => $record->body ?: $record->conditions ?: $record->visit_result),
                TextColumn::make('avisos')->label($f('notices'))->placeholder('—')
                    ->state(fn (PropertyUpdate $record): ?string => $record->notices->isEmpty() ? null : $record->notices->groupBy('status')
                        ->map(fn ($g, string $s): string => count($g).' '.mb_strtolower(__('colaboradores.notice_statuses.'.$s)))->implode(' · ')),
            ])
            ->headerActions([
                Action::make('note')->label(__('colaboradores.actions.note'))->icon('heroicon-m-lock-closed')->color('gray')
                    ->modalDescription(__('colaboradores.messages.internal_only'))
                    ->schema([Textarea::make('body')->label($f('note'))->required()->rows(4)->maxLength(5000)])
                    ->action(fn (array $data) => CollaboratorUi::attempt(fn () => Collaborations::note($this->property(), $data['body']), __('colaboradores.messages.noted'))),
                Action::make('share')->label(__('colaboradores.actions.share'))->icon('heroicon-m-paper-airplane')
                    ->visible(fn (): bool => Collaborations::enabled())
                    ->modalWidth('3xl')
                    ->schema(self::shareFields($property))
                    ->action(fn (array $data) => $this->share($data)),
            ])
            ->recordActions([
                Action::make('review')->label(__('colaboradores.actions.review'))->icon('heroicon-m-check-badge')->color('warning')
                    ->visible(fn (PropertyUpdate $record): bool => $record->status === PropertyUpdate::PENDING_REVIEW && Collaborations::enabled())
                    ->modalWidth('3xl')->modalDescription(__('colaboradores.actions.review_help'))
                    ->fillForm(fn (PropertyUpdate $record): array => $record->only(['title', 'conditions', 'next_steps', 'body']))
                    ->schema([
                        TextInput::make('title')->label($f('title'))->maxLength(190),
                        Textarea::make('conditions')->label($f('conditions'))->helperText($f('conditions_help'))->rows(4)->required(),
                        Textarea::make('next_steps')->label($f('next_steps'))->rows(2),
                        Textarea::make('body')->label($f('body'))->rows(3),
                        Html::make(fn (PropertyUpdate $record): string => self::previewHtml($record)),
                    ])
                    ->action(function (PropertyUpdate $record, array $data): void {
                        abort_unless((int) $record->team_id === CollaboratorUi::teamId(), 404);
                        $update = CollaboratorUi::attempt(fn () => Collaborations::approve($record, $data));
                        $update && self::sentNotification($update);
                    }),
                Action::make('discard')->label(__('colaboradores.actions.discard'))->icon('heroicon-m-x-mark')->color('gray')
                    ->visible(fn (PropertyUpdate $record): bool => $record->status === PropertyUpdate::PENDING_REVIEW)
                    ->requiresConfirmation()
                    ->action(function (PropertyUpdate $record): void {
                        abort_unless((int) $record->team_id === CollaboratorUi::teamId(), 404);
                        Collaborations::discard($record);
                        Notification::make()->title(__('colaboradores.messages.discarded'))->send();
                    }),
                Action::make('retry')->label(__('colaboradores.actions.retry'))->icon('heroicon-m-arrow-path')->color('gray')
                    ->visible(fn (PropertyUpdate $record): bool => Collaborations::enabled() && $record->notices->contains(fn (CollaboratorNotice $n): bool => $n->isOpen()))
                    ->action(function (PropertyUpdate $record): void {
                        abort_unless((int) $record->team_id === CollaboratorUi::teamId(), 404);
                        $record->notices->filter->isOpen()->each(fn (CollaboratorNotice $n) => CollaboratorNotices::dispatch($n));
                        self::sentNotification($record->fresh());
                    }),
                Action::make('files')->label($f('attachments'))->icon('heroicon-m-paper-clip')->color('gray')
                    ->visible(fn (PropertyUpdate $record): bool => filled($record->attachments))
                    ->modalSubmitAction(false)
                    ->schema([Html::make(fn (PropertyUpdate $record): string => collect($record->attachments)->values()->map(fn (array $a, int $i): string => '<p><a style="text-decoration:underline" target="_blank" href="'.e(route('colaboradores.adjunto', ['update' => $record->getKey(), 'index' => $i])).'">'.e($a['name']).'</a></p>')->implode(''))]),
            ]);
    }

    /** @return list<\Filament\Schemas\Components\Component> */
    public static function shareFields(Property $property): array
    {
        $f = fn (string $k): string => __('colaboradores.fields.'.$k);
        $is = fn (string ...$kinds): \Closure => fn (Get $get): bool => in_array($get('kind'), $kinds, true);

        return [
            Select::make('kind')->label($f('kind_update'))->required()->live()->native(false)
                ->options(CollaboratorUi::options('update_kinds', PropertyUpdate::SHARED_KINDS)),
            Select::make('recipients')->label($f('recipients'))->helperText($f('recipients_help'))->multiple()->native(false)
                ->options(fn (): array => CollaboratorProperty::query()->active()->with('collaborator')->where('property_id', $property->getKey())->get()
                    ->mapWithKeys(fn (CollaboratorProperty $a): array => [$a->collaborator_id => $a->collaborator?->name])->all()),
            TextInput::make('title')->label($f('title'))->maxLength(190),
            TextInput::make('price')->label($f('price'))->numeric()->minValue(0)->suffix('€')->visible($is('precio'))->required($is('precio')),
            Select::make('availability')->label($f('availability'))->native(false)->visible($is('disponibilidad'))->required($is('disponibilidad'))
                ->options(CollaboratorUi::options('availability', CollaboratorProperty::AVAILABILITY)),
            DateTimePicker::make('visit_at')->label($f('visit_at'))->seconds(false)->timezone('Europe/Madrid')->visible($is('visita', 'resultado_visita'))->required($is('visita')),
            Select::make('visit_status')->label($f('visit_status'))->native(false)->visible($is('visita', 'resultado_visita'))->required($is('visita'))
                ->options(CollaboratorUi::options('visit_statuses', PropertyUpdate::VISIT_STATUSES)),
            Textarea::make('visit_result')->label($f('visit_result'))->rows(3)->visible($is('visita', 'resultado_visita'))->required($is('resultado_visita')),
            Textarea::make('next_steps')->label($f('next_steps'))->rows(2)->visible($is('visita', 'resultado_visita', 'oferta', 'reserva', 'cierre')),
            Textarea::make('conditions')->label($f('conditions'))->helperText($f('conditions_help'))->rows(4)->visible($is(...PropertyUpdate::REVIEWED_KINDS))->required($is(...PropertyUpdate::REVIEWED_KINDS)),
            Textarea::make('body')->label($f('body'))->helperText($f('body_help'))->rows(4)->maxLength(5000)->required($is('otro')),
            FileUpload::make('files')->label($f('attachments'))->multiple()->maxFiles(5)->disk('local')->directory('colaboradores/'.$property->team_id)
                ->visibility('private')->acceptedFileTypes(self::MIMES)->maxSize(self::MAX_KB)->storeFileNamesIn('file_names'),
        ];
    }

    /** @param array<string, mixed> $data */
    public function share(array $data): void
    {
        $property = $this->property();
        $names = (array) ($data['file_names'] ?? []);
        $disk = Storage::disk('local');
        $data['attachments'] = collect((array) ($data['files'] ?? []))->filter(fn ($p): bool => is_string($p) && $disk->exists($p))
            ->filter(fn (string $p): bool => in_array($disk->mimeType($p), self::MIMES, true) && $disk->size($p) <= self::MAX_KB * 1024)
            ->map(fn (string $p): array => ['path' => $p, 'name' => (string) ($names[$p] ?? basename($p)), 'mime' => $disk->mimeType($p), 'size' => $disk->size($p)])->values()->all() ?: null;
        unset($data['files'], $data['file_names']);

        if (Collaborations::recipients(new PropertyUpdate(['property_id' => $property->getKey(), 'team_id' => $property->team_id, 'recipients' => $data['recipients'] ?? null]))->isEmpty()) {
            Notification::make()->warning()->title(__('colaboradores.messages.no_recipients'))->send();

            return;
        }
        $update = CollaboratorUi::attempt(fn () => Collaborations::share($property, $data));
        if ($update?->status === PropertyUpdate::PENDING_REVIEW) {
            Notification::make()->warning()->title(__('colaboradores.messages.pending_review'))->send();
        } elseif ($update !== null) {
            self::sentNotification($update);
        }
    }

    private static function sentNotification(PropertyUpdate $update): void
    {
        $notices = $update->notices()->get();
        $failed = $notices->whereIn('status', [CollaboratorNotice::ERROR, CollaboratorNotice::PARTIAL])->count();
        Notification::make()->title(__('colaboradores.messages.shared', ['sent' => $notices->where('status', CollaboratorNotice::SENT)->count(), 'failed' => $failed]))
            ->{$failed > 0 ? 'warning' : 'success'}()->send();
    }

    /** What the first recipient would receive, as saved (e-mail; WhatsApp carries the same content). */
    public static function previewHtml(PropertyUpdate $update): string
    {
        $assignment = Collaborations::recipients($update)->first();
        if ($assignment === null) {
            return '<p style="opacity:.7">'.e(__('colaboradores.messages.no_recipients')).'</p>';
        }
        $m = UpdateMessage::render($update, $assignment->collaborator, $assignment);

        return '<div style="border:1px solid rgba(128,128,128,.3);border-radius:.5rem;padding:.75rem;font-size:.85rem">'
            .'<p style="font-weight:600;margin-bottom:.4rem">'.e(__('colaboradores.actions.preview')).' · '.e($assignment->collaborator->name).' · '.e($m['subject']).'</p>'
            .'<pre style="white-space:pre-wrap;font-family:inherit">'.e($m['email']).'</pre></div>';
    }

    public function render(): View
    {
        return view('filament.collaborators.table');
    }
}
