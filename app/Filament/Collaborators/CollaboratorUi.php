<?php

declare(strict_types=1);

namespace App\Filament\Collaborators;

use App\Collaborators\Collaborations;
use App\Collaborators\Collaborator;
use App\Collaborators\CollaboratorAccess;
use App\Collaborators\CollaboratorNotice;
use App\Collaborators\CollaboratorNotices;
use Closure;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use RuntimeException;

/** Shared helpers of the staff screens of «Colaboradores». */
final class CollaboratorUi
{
    /** @return array<string, string> */
    public static function options(string $group, array $values): array
    {
        return collect($values)->mapWithKeys(fn (string $v): array => [$v => __('colaboradores.'.$group.'.'.$v)])->all();
    }

    public static function label(string $group): Closure
    {
        return fn (?string $state): string => filled($state) ? __('colaboradores.'.$group.'.'.$state) : '—';
    }

    /** Runs a staff operation; a refusal becomes a red notification instead of an error page. */
    public static function attempt(Closure $work, ?string $success = null): mixed
    {
        try {
            $result = $work();
            if ($success !== null) {
                Notification::make()->success()->title($success)->send();
            }

            return $result;
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title($e->getMessage())->persistent()->send();

            return null;
        }
    }

    public static function staff(): bool
    {
        return CollaboratorAccess::isStaff(auth()->user());
    }

    public static function teamId(): int
    {
        return (int) (\Filament\Facades\Filament::getTenant()?->getKey() ?? auth()->user()?->current_team_id);
    }

    /** @return array<int, string> active collaborators of the team */
    public static function collaboratorOptions(): array
    {
        return Collaborator::query()->where('team_id', self::teamId())->where('status', '!=', Collaborator::INACTIVE)->orderBy('name')->pluck('name', 'id')->all();
    }

    public static function noticeStatusColumn(string $name, string $group): TextColumn
    {
        return TextColumn::make($name)->badge()->formatStateUsing(self::label($group))->placeholder('—')
            ->color(fn (?string $state): string => match ($state) { 'enviado' => 'success', 'error' => 'danger', 'parcial', 'pendiente' => 'warning', default => 'gray' });
    }

    public static function retryAction(): Action
    {
        return Action::make('retry')->label(__('colaboradores.actions.retry'))->icon('heroicon-o-arrow-path')->color('gray')
            ->visible(fn (CollaboratorNotice $record): bool => $record->isOpen() && Collaborations::enabled())
            ->action(function (CollaboratorNotice $record): void {
                abort_unless((int) $record->team_id === self::teamId(), 404);
                $n = CollaboratorNotices::dispatch($record);
                Notification::make()->title(__('colaboradores.messages.retried', ['status' => __('colaboradores.notice_statuses.'.$n->status)]))
                    ->{$n->status === CollaboratorNotice::SENT ? 'success' : 'warning'}()->send();
            });
    }
}
