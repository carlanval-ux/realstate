<?php

declare(strict_types=1);

namespace App\Filament\Alquileres\Resources\OccupancyResource\Pages;

use App\Filament\Alquileres\RentalUi;
use App\Filament\Alquileres\Resources\OccupancyResource;
use App\Rentals\Models\Occupancy;
use App\Rentals\Models\RentalUnit;
use App\Rentals\Occupancies;
use App\Rentals\RentalAccess;
use App\Rentals\RentalFlows;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ViewRecord;

/** Record page: the lifecycle actions (keys/check-in, extension, notice, exit/check-out, cancel, room change, settlement). */
final class ViewOccupancy extends ViewRecord
{
    protected static string $resource = OccupancyResource::class;

    protected function getHeaderActions(): array
    {
        $f = fn (string $key): string => __('clarto.alquileres.fields.'.$key);
        $a = fn (string $key): string => __('clarto.alquileres.actions.'.$key);
        $o = fn (): Occupancy => $this->getRecord();
        $manage = fn (): bool => RentalUi::can(RentalAccess::MANAGE);
        $holiday = fn (): bool => $o()->module === 'vacacional';
        $status = fn (string ...$s): bool => in_array($o()->status, $s, true);
        $refresh = fn () => $this->getRecord()->refresh();

        return [
            Action::make('start')->label(fn (): string => $holiday() ? $a('checkin') : $a('start'))->icon('heroicon-o-key')
                ->visible(fn (): bool => $manage() && $status('confirmada') && $o()->kind !== 'bloqueo')
                ->schema([
                    DatePicker::make('date')->label($f('date'))->required()->default(fn () => $o()->planned_start),
                    TextInput::make('keys')->label($f('keys'))->numeric()->minValue(0)->hidden($holiday),
                ])
                ->action(function (array $data) use ($o, $holiday, $refresh): void {
                    RentalUi::attempt(fn () => $holiday()
                        ? RentalFlows::checkIn($o(), $data['date'])
                        : Occupancies::start($o(), $data['date'], 'entrega_llaves', ['juegos_llaves' => $data['keys'] ?? null]));
                    $refresh();
                }),
            Action::make('finish')->label(fn (): string => $holiday() ? $a('checkout') : $a('finish'))->icon('heroicon-o-arrow-right-start-on-rectangle')
                ->visible(fn (): bool => $manage() && $status('confirmada', 'en_curso') && $o()->kind !== 'bloqueo')
                ->schema([
                    DatePicker::make('date')->label($f('date'))->required()->default(fn () => $o()->planned_end),
                    TextInput::make('reason')->label($f('reason'))->required(fn (): bool => ! $holiday())->hidden($holiday)->maxLength(40),
                ])
                ->action(function (array $data) use ($o, $holiday, $refresh): void {
                    RentalUi::attempt(fn () => $holiday() ? RentalFlows::checkOut($o(), $data['date']) : Occupancies::finish($o(), $data['date'], $data['reason']));
                    $refresh();
                }),
            ActionGroup::make([
                Action::make('extend')->label($a('extend'))->icon('heroicon-o-calendar-days')
                    ->visible(fn (): bool => $manage() && $status('confirmada', 'en_curso') && ! $holiday())
                    ->schema([DatePicker::make('new_end')->label($f('new_end'))->required()->after(fn () => $o()->planned_end)])
                    ->action(function (array $data) use ($o, $refresh): void {
                        RentalUi::attempt(fn () => Occupancies::extend($o(), $data['new_end']));
                        $refresh();
                    }),
                Action::make('notice')->label(__('clarto.alquileres.events.preaviso'))->icon('heroicon-o-bell-alert')
                    ->visible(fn (): bool => $manage() && $status('confirmada', 'en_curso') && ! $holiday())
                    ->schema([
                        Select::make('from')->label($f('emitter'))->required()->options(RentalUi::options('roles', ['arrendatario', 'propietario'])),
                        DatePicker::make('date')->label($f('date'))->required()->default(now()),
                        DatePicker::make('exit')->label($f('planned_end'))->required(),
                    ])
                    ->action(fn (array $data) => RentalUi::attempt(fn () => Occupancies::record($o(), 'preaviso', ['emisor' => $data['from'], 'fecha' => $data['date'], 'salida_prevista' => $data['exit']]))),
                Action::make('change_room')->label($a('change_room'))->icon('heroicon-o-arrows-right-left')
                    ->visible(fn (): bool => $manage() && $status('confirmada', 'en_curso') && $o()->module === 'habitaciones')
                    ->schema([
                        Select::make('room')->label($f('room'))->required()
                            ->options(fn (): array => RentalUnit::query()->where('parent_id', $o()->unit?->parent_id)->whereKeyNot($o()->unit_id)->where('active', true)->pluck('name', 'id')->all()),
                        DatePicker::make('date')->label($f('date'))->required(),
                    ])
                    ->action(function (array $data) use ($o): void {
                        $next = RentalUi::attempt(fn () => Occupancies::changeRoom($o(), RentalUnit::query()->findOrFail($data['room']), $data['date']));
                        $this->redirect(OccupancyResource::getUrl('view', ['record' => $next]));
                    }),
                Action::make('issue_rent')->label($a('issue_rent'))->icon('heroicon-o-banknotes')
                    ->visible(fn (): bool => $manage() && $status('confirmada', 'en_curso') && $o()->rent_period === 'mes')
                    ->schema([TextInput::make('period')->label($f('period'))->required()->regex('/^\d{4}-\d{2}$/')->default(now()->format('Y-m'))])
                    ->action(fn (array $data) => RentalUi::attempt(fn () => RentalFlows::issueRent($o(), $data['period']))),
                Action::make('communicate')->label($a('communicate'))->icon('heroicon-o-chat-bubble-left-right')
                    ->visible($manage)
                    ->schema([
                        Select::make('channel')->label($f('channel'))->required()->options(RentalUi::options('comm_channels', RentalFlows::CHANNELS)),
                        Select::make('purpose')->label($f('purpose'))->required()->options(RentalUi::options('comm_purposes', RentalFlows::PURPOSES)),
                        TextInput::make('to')->label($f('to'))->required()->maxLength(255),
                        TextInput::make('subject')->label($f('subject'))->required()->maxLength(255),
                        Textarea::make('body')->label($f('body'))->required()->rows(4),
                    ])
                    ->action(fn (array $data) => RentalUi::attempt(fn () => RentalFlows::communicate($o(), $data['channel'], $data['purpose'], $data['to'], $data['subject'], $data['body']))),
                Action::make('settle_owner')->label($a('settle_owner'))->icon('heroicon-o-calculator')
                    ->visible(fn (): bool => $manage() && $holiday() && $status('finalizada') && $o()->kind === 'reserva')
                    ->requiresConfirmation()
                    ->action(fn () => RentalUi::attempt(fn () => RentalFlows::settleOwner($o()))),
                Action::make('cancel')->label($a('cancel'))->icon('heroicon-o-x-circle')->color('danger')
                    ->visible(fn (): bool => $manage() && $status('borrador', 'confirmada'))
                    ->schema([
                        TextInput::make('reason')->label($f('reason'))->required()->maxLength(40),
                        TextInput::make('refund')->label($f('refund_percent'))->numeric()->minValue(0)->maxValue(100)->default(100)->visible($holiday),
                    ])
                    ->action(function (array $data) use ($o, $holiday, $refresh): void {
                        RentalUi::attempt(fn () => $holiday() && $o()->kind === 'reserva'
                            ? RentalFlows::cancelHoliday($o(), (float) ($data['refund'] ?? 0), $data['reason'], now())
                            : Occupancies::cancel($o(), $data['reason']));
                        $refresh();
                    }),
            ]),
        ];
    }
}
