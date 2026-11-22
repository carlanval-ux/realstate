<?php

declare(strict_types=1);

namespace App\Filament\Collaborators;

use App\Collaborators\Collaborator;
use App\Collaborators\Import\CollaboratorImport;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Str;

/** «Importación de inmuebles» on the collaborator's record (staff) and its «Importar ahora» action. */
final class CollaboratorImportUi
{
    public static function section(): Section
    {
        $f = fn (string $k): string => __('importacion.fields.'.$k);
        $source = fn (Get $get): ?string => $get('source');
        $generic = fn (Get $get): bool => $get('source') === CollaboratorImport::FEED && in_array($get('feed_format'), ['xml', 'json'], true);
        $options = fn (string $group, array $keys): array => collect($keys)->mapWithKeys(fn (string $k): array => [$k => __("importacion.{$group}.{$k}")])->all();

        return Section::make(__('importacion.section'))->icon('heroicon-o-arrow-down-tray')->collapsible()->visibleOn('edit')
            ->relationship('import')
            ->mutateRelationshipDataBeforeCreateUsing(fn (array $data): array => $data + ['team_id' => CollaboratorUi::teamId(), 'updated_by' => auth()->id()])
            ->mutateRelationshipDataBeforeSaveUsing(fn (array $data): array => $data + ['updated_by' => auth()->id()])
            ->columns(2)
            ->schema([
                Callout::make(__('importacion.gate.title'))->warning()->columnSpanFull()
                    ->description(fn (Get $get): string => filled($get('authorized_at')) ? __('importacion.gate.ok') : __('importacion.gate.help').' '.(self::anyAuthorized() ? '' : __('importacion.gate.none_signed'))),
                ToggleButtons::make('source')->label($f('source'))->options($options('sources', CollaboratorImport::SOURCES))->default(CollaboratorImport::NONE)
                    ->inline()->required()->live()->columnSpanFull(),

                TextInput::make('feed_url')->label($f('feed_url'))->url()->maxLength(1000)->required(fn (Get $get): bool => $source($get) === CollaboratorImport::FEED)
                    ->visible(fn (Get $get): bool => $source($get) === CollaboratorImport::FEED),
                Select::make('feed_format')->label($f('feed_format'))->options($options('formats', CollaboratorImport::FORMATS))->default('kyero')->native(false)->live()
                    ->required(fn (Get $get): bool => $source($get) === CollaboratorImport::FEED)->visible(fn (Get $get): bool => $source($get) === CollaboratorImport::FEED),
                Fieldset::make($f('mapping'))->columns(3)->columnSpanFull()->visible($generic)->schema([
                    Text::make($f('mapping_help'))->columnSpanFull(),
                    ...collect(CollaboratorImport::MAPPABLE)->map(fn (string $k) => TextInput::make('feed_mapping.'.$k)->label(__('importacion.mapping.'.$k))
                        ->maxLength(200)->required(in_array($k, ['elementos', 'referencia', 'precio'], true) ? $generic : false))->all(),
                ]),

                TextInput::make('web_url')->label($f('web_url'))->helperText($f('web_url_help'))->url()->maxLength(1000)
                    ->required(fn (Get $get): bool => $source($get) === CollaboratorImport::WEB)->visible(fn (Get $get): bool => $source($get) === CollaboratorImport::WEB),
                TextInput::make('web_pattern')->label($f('web_pattern'))->helperText($f('web_pattern_help'))->maxLength(500)->placeholder('/inmueble/*')
                    ->visible(fn (Get $get): bool => $source($get) === CollaboratorImport::WEB),

                ToggleButtons::make('schedule')->label($f('schedule'))->options($options('schedules', CollaboratorImport::SCHEDULES))->default('diario')->inline()
                    ->visible(fn (Get $get): bool => $source($get) !== CollaboratorImport::NONE),
                DatePicker::make('authorized_at')->label($f('authorized_at'))->helperText($f('authorized_at_help'))->native(false)->displayFormat('d/m/Y')
                    ->maxDate(now())->live(),
                FileUpload::make('authorization_path')->label($f('authorization_doc'))->helperText($f('authorization_doc_help'))
                    ->disk('local')->directory(fn (): string => 'colaboradores/autorizaciones/'.CollaboratorUi::teamId())->visibility('private')
                    ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])->maxSize(10240)->downloadable()->columnSpanFull(),
                Toggle::make('portals_allowed')->label($f('portals_allowed'))->helperText($f('portals_allowed_help'))->default(false)->columnSpanFull(),

                Text::make(fn (?CollaboratorImport $record): string => self::lastRun($record))->columnSpanFull(),
            ]);
    }

    public static function action(Collaborator $collaborator): Action
    {
        $current = fn (): ?CollaboratorImport => $collaborator->import()->first();   // fresh: the form may have just saved it

        return Action::make('import_now')->label(__('importacion.actions.now'))->icon('heroicon-o-arrow-down-tray')->color('gray')
            ->visible(fn (): bool => ($current()?->source ?? CollaboratorImport::NONE) !== CollaboratorImport::NONE)
            ->disabled(fn (): bool => ! ($current()?->isAuthorized() ?? false))
            ->tooltip(fn (): ?string => ($current()?->isAuthorized() ?? false) ? null : __('importacion.gate.refused'))
            ->requiresConfirmation()->modalDescription(__('importacion.actions.now_help'))
            ->action(function () use ($collaborator, $current): void {
                $import = $current();
                abort_unless($import !== null && (int) $import->team_id === CollaboratorUi::teamId(), 404);
                if (! $import->isAuthorized()) {   // the gate is enforced again by CollaboratorImports::run
                    Notification::make()->danger()->title(__('importacion.gate.refused'))->send();

                    return;
                }
                $import->forceFill(['requested_at' => now(), 'requested_by' => auth()->id()])->save();
                Collaborator::log($import, 'importacion_solicitada', ['colaborador' => $collaborator->id]);
                Notification::make()->success()->title(__('importacion.actions.requested'))->send();
            });
    }

    private static function lastRun(?CollaboratorImport $import): string
    {
        if ($import === null || $import->last_run_at === null) {
            return __('importacion.status.never').($import?->requested_at ? ' '.__('importacion.status.requested') : '');
        }
        $counts = collect($import->last_counts ?? [])->filter()->map(fn (int $n, string $k): string => __('importacion.counts.'.$k).': '.$n)->implode(' · ');

        return __('importacion.status.last', ['date' => $import->last_run_at->format('d/m/Y H:i'), 'status' => __('importacion.status.'.$import->last_status)])
            .($counts !== '' ? ' — '.$counts : '').($import->last_message ? ' — '.Str::limit($import->last_message, 300) : '')
            .($import->requested_at ? ' '.__('importacion.status.requested') : '');
    }

    private static function anyAuthorized(): bool
    {
        return CollaboratorImport::query()->where('team_id', CollaboratorUi::teamId())->whereNotNull('authorized_at')->exists();
    }
}
