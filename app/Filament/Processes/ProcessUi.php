<?php

declare(strict_types=1);

namespace App\Filament\Processes;

use App\Models\User;
use App\Modules\ClartoModules;
use App\Processes\Models\Process;
use Filament\Facades\Filament;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/** Shared helpers of the «Procesos y conocimiento» screens. */
final class ProcessUi
{
    public static function group(): string
    {
        return __('procesos.modulo');
    }

    public static function user(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    public static function team(): int|string|null
    {
        return Filament::getTenant()?->getKey();
    }

    /** New operations (create, send, publish) need the module switched on; reading never does. */
    public static function moduleOn(): bool
    {
        return ! class_exists(ClartoModules::class) || ! in_array('procesos', ClartoModules::keys(), true) || ClartoModules::enabled('procesos');
    }

    /** Markdown without raw HTML or unsafe links. */
    public static function md(?string $markdown): HtmlString
    {
        return new HtmlString(blank($markdown) ? '' : Str::markdown((string) $markdown, ['html_input' => 'strip', 'allow_unsafe_links' => false]));
    }

    /** The process of the current tenant, or 404 (never reveals a process of another company). */
    public static function process(int|string $id): Process
    {
        $process = Process::query()->whereKey($id)->where('team_id', self::team())->first();
        abort_if($process === null, 404);

        return $process;
    }

    /** @return array<string, string> */
    public static function options(string $group, array $keys): array
    {
        return array_combine($keys, array_map(fn (string $k): string => __("procesos.{$group}.{$k}"), $keys));
    }

    /**
     * «Asignar responsable y aprobador» without opening a new version (logged as responsable_cambiado /
     * metadatos_cambiados). $process resolves the process from the action's arguments.
     *
     * @param  \Closure(array): Process  $process
     */
    public static function assignAction(string $name, \Closure $process): \Filament\Actions\Action
    {
        $team = self::team();

        return \Filament\Actions\Action::make($name)->label(__('procesos.gestion.asignar'))->icon('heroicon-o-user-circle')
            ->visible(fn (array $arguments = []): bool => self::moduleOn() && (\App\Processes\ProcessAccess::canAdminister(self::user(), $team)
                || ($arguments === [] && \App\Processes\ProcessAccess::canMaintain(self::user(), $process($arguments)))))
            ->modalDescription(__('procesos.gestion.asignar_ayuda'))
            ->fillForm(fn (array $arguments): array => $process($arguments)->only(['owner_user_id', 'approver_user_id']))
            ->schema([
                \Filament\Forms\Components\Select::make('owner_user_id')->label(__('procesos.campos.owner'))->searchable()->options(fn (): array => self::users($team)),
                \Filament\Forms\Components\Select::make('approver_user_id')->label(__('procesos.campos.approver'))->searchable()->options(fn (): array => self::users($team)),
            ])
            ->action(function (array $data, array $arguments) use ($process): void {
                \App\Processes\Processes::updateMetadata(self::user(), $process($arguments), [
                    'owner_user_id' => filled($data['owner_user_id'] ?? null) ? (int) $data['owner_user_id'] : null,
                    'approver_user_id' => filled($data['approver_user_id'] ?? null) ? (int) $data['approver_user_id'] : null,
                ]);
                \Filament\Notifications\Notification::make()->title(__('procesos.editor.guardado'))->success()->send();
            });
    }

    /** @return array<int, string> active users of the company */
    public static function users(int|string $teamId): array
    {
        return User::query()->whereNull('disabled_at')
            ->where(fn ($q) => $q->whereIn('id', \Illuminate\Support\Facades\DB::table('team_user')->where('team_id', $teamId)->select('user_id'))
                ->orWhereIn('id', \Illuminate\Support\Facades\DB::table('teams')->where('id', $teamId)->select('user_id')))
            ->orderBy('name')->pluck('name', 'id')->all();
    }
}
