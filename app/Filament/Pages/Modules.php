<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Modules\ClartoModules;
use App\Modules\ModuleStatus;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Size;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;
use Liberu\Foundation\ModuleManager\Exceptions\ModuleActivationRefused;
use Liberu\Foundation\ModuleManager\ModuleManagement;

/**
 * Platform settings → Modules: switch feature modules on and off.
 *
 * Lists the official feature modules exactly as the module manager's own page does (same ModuleManagement service,
 * same module_activations table) and CLARTO's feature modules (App\Modules\ClartoModules), with version and status.
 * Operational configuration stays on each setting page; a module that is on but lacks credentials or a session shows
 * «Pendiente de configurar» with a link there. Switching off stops new operations; history and documents are kept.
 */
final class Modules extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-puzzle-piece';

    protected static ?string $slug = 'modulos';

    protected static ?int $navigationSort = 50;

    public static function getNavigationLabel(): string
    {
        return __('Modules');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('filament.nav_groups.platform_settings');
    }

    public function getTitle(): string
    {
        return __('Modules');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && Gate::forUser($user)->allows(ClartoModules::ABILITY);
    }

    public function getSubheading(): ?string
    {
        return __('Switching a module off stops new operations (actions, API and webhooks). Its history and documents are kept, and switching it on again restores them. Credentials and sessions are configured on each setting page.');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => $this->rows())
            ->paginated(false)
            ->emptyStateIcon('heroicon-o-puzzle-piece')
            ->emptyStateHeading(__('No feature modules are installed'))
            ->columns([
                TextColumn::make('name')
                    ->label(__('Module'))
                    ->weight('medium')
                    ->description(fn (array $record): ?string => filled($record['description'] ?? null) ? $record['description'] : null)
                    ->wrap(),
                TextColumn::make('version')->label(__('Version'))->color('gray'),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::status($state)?->label() ?? ucfirst($state))
                    ->color(fn (string $state): string => self::status($state)?->color() ?? 'gray')
                    ->icon(fn (string $state): string => self::status($state)?->icon() ?? 'heroicon-m-minus-circle'),
                TextColumn::make('note')->label('')->wrap()->color('gray'),
            ])
            ->recordActions([
                Action::make('configure')
                    ->label(__('Configure'))
                    ->button()
                    ->size(Size::Small)
                    ->color('warning')
                    ->icon('heroicon-m-cog-6-tooth')
                    ->url(fn (array $record): ?string => $record['settings_url'] ?? null)
                    ->visible(fn (array $record): bool => filled($record['settings_url'] ?? null)),
                Action::make('enable')
                    ->label(__('Enable'))
                    ->button()
                    ->size(Size::Small)
                    ->color('success')
                    ->icon('heroicon-m-play')
                    ->visible(fn (array $record): bool => $record['changeable'] && $record['status'] === ModuleStatus::Disabled->value)
                    ->action(fn (array $record) => $this->change($record, true)),
                Action::make('disable')
                    ->label(__('Disable'))
                    ->button()
                    ->size(Size::Small)
                    ->color('danger')
                    ->icon('heroicon-m-pause')
                    ->requiresConfirmation()
                    ->modalHeading(fn (array $record): string => __('Disable :module?', ['module' => $record['name']]))
                    ->modalDescription(__('New operations of this module are refused from the next request (actions, API and webhooks). Its history and documents are kept.'))
                    ->modalSubmitActionLabel(__('Disable'))
                    ->visible(fn (array $record): bool => $record['changeable'] && $record['status'] !== ModuleStatus::Disabled->value)
                    ->action(fn (array $record) => $this->change($record, false)),
            ]);
    }

    /** @param  array<string, mixed>  $record */
    private function change(array $record, bool $enable): void
    {
        abort_unless(self::canAccess(), 403);

        try {
            if ($record['source'] === 'clarto') {
                $enable ? ClartoModules::enable($record['key']) : ClartoModules::disable($record['key']);
            } else {
                $enable ? app(ModuleManagement::class)->enable($record['module']) : app(ModuleManagement::class)->disable($record['module']);
            }
            Notification::make()
                ->title($enable ? __('Module enabled') : __('Module disabled'))
                ->body(__('This takes effect from the next request.'))
                ->success()->send();
        } catch (ModuleActivationRefused $exception) {
            Notification::make()->title(__('Not changed'))->body($exception->getMessage())->danger()->send();
        }

        $this->flushCachedTableRecords();
    }

    /** @return array<string, array<string, mixed>> */
    private function rows(): array
    {
        $rows = [];

        // Official feature modules (none of the installed packages declares one today).
        foreach (app(ModuleManagement::class)->modules() as $name => $module) {
            $rows[$name] = $module + [
                'key' => $name,
                'source' => 'liberu',
                'note' => $module['problems'] !== [] ? implode(' ', $module['problems']) : $module['locked'],
                'settings_url' => null,
                'changeable' => $module['locked'] === null,
            ];
        }

        return $rows + ClartoModules::rows();
    }

    private static function status(string $state): ?ModuleStatus
    {
        return ModuleStatus::tryFrom($state);
    }
}
