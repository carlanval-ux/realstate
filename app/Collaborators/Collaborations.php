<?php

declare(strict_types=1);

namespace App\Collaborators;

use App\Models\User;
use App\Modules\ClartoModules;
use App\Tarmio\TarmioTask;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\RealEstate\Properties\Domain\PropertyStatus;
use Liberu\RealEstate\Properties\Models\Property;
use Liberu\RealEstate\PropertiesFilament\Resources\PropertyResource;
use RuntimeException;
use Throwable;

/**
 * Staff operations of «Colaboradores»: invitation, assignment and withdrawal, internal notes and shared updates.
 * Every operation checks the team, writes who did it and leaves an activity_log entry ('colaboradores').
 */
final class Collaborations
{
    public const MODULE = 'colaboradores';

    public static function enabled(): bool
    {
        return ! class_exists(ClartoModules::class) || ! in_array(self::MODULE, ClartoModules::keys(), true) || ClartoModules::enabled(self::MODULE);
    }

    public static function ensureEnabled(): void
    {
        if (! self::enabled()) {
            throw new RuntimeException(__('colaboradores.module.off'));
        }
    }

    /**
     * Creates or links the collaborator's panel user (no team, role «colaborador» of the collaborator's team) and
     * e-mails the activation link to the collaborator panel.
     *
     * @return array{ok: bool, message: string}
     */
    public static function invite(Collaborator $collaborator): array
    {
        self::ensureEnabled();
        if (blank($collaborator->email)) {
            return ['ok' => false, 'message' => __('colaboradores.messages.no_email')];
        }

        $user = $collaborator->user;
        if ($user === null || $user->email !== $collaborator->email) {
            $user = User::query()->where('email', $collaborator->email)->first();
            if ($user !== null && CollaboratorAccess::isStaff($user)) {
                return ['ok' => false, 'message' => __('colaboradores.messages.email_is_staff')];
            }
            if ($user !== null && Collaborator::query()->where('user_id', $user->id)->whereKeyNot($collaborator->getKey())->exists()) {
                return ['ok' => false, 'message' => __('colaboradores.messages.email_in_use')];
            }
            $user ??= User::query()->create(['name' => $collaborator->name, 'email' => $collaborator->email, 'locale' => $collaborator->locale]);
            $collaborator->user_id = $user->id;
        }
        self::grantRole($user, (int) $collaborator->team_id);

        try {
            $status = Password::broker()->sendResetLink(['email' => $user->email],
                fn (User $u, string $token) => $u->notify(new CollaboratorInvitation($token, $collaborator->locale ?: 'es')));
        } catch (Throwable $e) {
            report($e);

            return ['ok' => false, 'message' => __('The e-mail could not be sent (:reason). Check the mail settings of the server.', ['reason' => class_basename($e)])];
        }
        if ($status !== Password::RESET_LINK_SENT) {
            return ['ok' => false, 'message' => __($status)];
        }

        $collaborator->forceFill(['invited_at' => now(), 'updated_by' => auth()->id()])->save();
        Collaborator::log($collaborator, 'invitado', ['email' => $collaborator->email]);

        return ['ok' => true, 'message' => __('colaboradores.messages.invited', ['email' => $collaborator->email])];
    }

    /** The collaborator chose a password from the invitation: their account is active. */
    public static function activated(object $user): void
    {
        $collaborator = Collaborator::query()->where('user_id', $user->id ?? 0)->where('status', Collaborator::PENDING)->first();
        if ($collaborator !== null) {
            $collaborator->forceFill(['status' => Collaborator::ACTIVE, 'activated_at' => now()])->save();
            Collaborator::log($collaborator, 'activado');
        }
    }

    public static function deactivate(Collaborator $collaborator): int
    {
        $collaborator->forceFill(['status' => Collaborator::INACTIVE, 'deactivated_at' => now(), 'updated_by' => auth()->id()])->save();
        $n = 0;
        $collaborator->notices()->whereIn('status', CollaboratorNotice::OPEN)->each(function (CollaboratorNotice $notice) use (&$n): void {
            CollaboratorNotices::cancel($notice, 'baja');
            $n++;
        });
        Collaborator::log($collaborator, 'baja', ['avisos_cancelados' => $n]);

        return $n;
    }

    public static function reactivate(Collaborator $collaborator): void
    {
        $collaborator->forceFill([
            'status' => $collaborator->activated_at ? Collaborator::ACTIVE : Collaborator::PENDING,
            'deactivated_at' => null, 'updated_by' => auth()->id(),
        ])->save();
        Collaborator::log($collaborator, 'reactivado');
    }

    /** Assigns the property (one active assignment per collaborator and property). Optionally announces it. */
    public static function assign(Property $property, Collaborator $collaborator, string $relation, mixed $authorisedPrice = null, bool $announce = true, ?string $message = null): CollaboratorProperty
    {
        self::ensureEnabled();
        throw_unless((string) $property->team_id === (string) $collaborator->team_id, new RuntimeException(__('colaboradores.messages.other_team')));
        throw_unless(in_array($relation, CollaboratorProperty::RELATIONS, true), new RuntimeException('relation'));
        throw_if($collaborator->status === Collaborator::INACTIVE, new RuntimeException(__('colaboradores.statuses.baja')));
        $exists = CollaboratorProperty::query()->active()->where('collaborator_id', $collaborator->id)->where('property_id', $property->getKey())->exists();
        throw_if($exists, new RuntimeException(__('colaboradores.messages.already_assigned', ['name' => $collaborator->name])));

        $assignment = CollaboratorProperty::query()->create([
            'team_id' => $property->team_id,
            'collaborator_id' => $collaborator->id,
            'property_id' => $property->getKey(),
            'relation' => $relation,
            'authorised_price' => $authorisedPrice !== null && $authorisedPrice !== '' ? $authorisedPrice : $property->price,
            'availability' => self::availabilityOf($property),
            'commercial_status' => 'en_comercializacion',
            'assigned_by' => auth()->id(),
            'assigned_at' => now(),
        ]);
        Collaborator::log($assignment, 'asignado', ['colaborador' => $collaborator->id, 'inmueble' => $property->getKey(), 'relacion' => $relation]);
        self::followUpTask($assignment);

        if ($announce) {
            self::share($property, ['kind' => 'alta', 'body' => $message, 'recipients' => [$collaborator->id]]);
        }

        return $assignment->fresh();
    }

    /** Withdraws the assignment: access ends now and the pending notices of this property are cancelled. */
    public static function revoke(CollaboratorProperty $assignment, ?string $reason = null): int
    {
        if (! $assignment->isActive()) {
            return 0;
        }
        $assignment->forceFill(['revoked_at' => now(), 'revoked_by' => auth()->id(), 'revoke_reason' => $reason])->save();
        $updates = PropertyUpdate::query()->where('property_id', $assignment->property_id)->pluck('id');
        $n = CollaboratorNotices::cancelOpen($assignment->collaborator, (new PropertyUpdate)->getMorphClass(), $updates, 'asignacion_retirada');
        Collaborator::log($assignment, 'retirado', ['motivo' => $reason, 'avisos_cancelados' => $n]);

        return $n;
    }

    public static function note(Property $property, string $body): PropertyUpdate
    {
        $update = PropertyUpdate::query()->create([
            'team_id' => $property->team_id, 'property_id' => $property->getKey(), 'kind' => 'nota',
            'visibility' => PropertyUpdate::INTERNAL, 'status' => PropertyUpdate::NOTE, 'body' => trim($body), 'created_by' => auth()->id(),
        ]);
        Collaborator::log($update, 'nota_interna');

        return $update;
    }

    /**
     * A shared update. Offer, reservation and closing wait for the team's review (approve()); the rest go out now.
     *
     * @param  array<string, mixed>  $data  kind, title, body, price, availability, visit_at, visit_status, visit_result, next_steps, conditions, attachments, recipients
     */
    public static function share(Property $property, array $data): PropertyUpdate
    {
        self::ensureEnabled();
        $kind = (string) ($data['kind'] ?? '');
        throw_unless(in_array($kind, PropertyUpdate::SHARED_KINDS, true), new RuntimeException('kind'));
        $recipients = array_values(array_map('intval', array_filter((array) ($data['recipients'] ?? []))));

        $update = PropertyUpdate::query()->create([
            'team_id' => $property->team_id, 'property_id' => $property->getKey(), 'kind' => $kind,
            'visibility' => PropertyUpdate::SHARED,
            'status' => in_array($kind, PropertyUpdate::REVIEWED_KINDS, true) ? PropertyUpdate::PENDING_REVIEW : PropertyUpdate::SENT,
            'title' => $data['title'] ?? null, 'body' => filled($data['body'] ?? null) ? trim((string) $data['body']) : null,
            'price' => filled($data['price'] ?? null) ? $data['price'] : null,
            'availability' => $data['availability'] ?? null,
            'visit_at' => $data['visit_at'] ?? null, 'visit_status' => $data['visit_status'] ?? null,
            'visit_result' => $data['visit_result'] ?? null, 'next_steps' => $data['next_steps'] ?? null,
            'conditions' => $data['conditions'] ?? null, 'attachments' => $data['attachments'] ?? null,
            'recipients' => $recipients === [] ? null : $recipients,
            'created_by' => auth()->id(),
        ]);
        Collaborator::log($update, 'novedad_creada', ['tipo' => $kind]);

        if ($update->status === PropertyUpdate::PENDING_REVIEW) {
            self::reviewTask($update);

            return $update;
        }
        self::publish($update);

        return $update->fresh();
    }

    /** The team reviewed (and possibly edited) what is communicated: send it now. */
    public static function approve(PropertyUpdate $update, array $edits = []): PropertyUpdate
    {
        self::ensureEnabled();
        throw_unless($update->status === PropertyUpdate::PENDING_REVIEW, new RuntimeException(__('colaboradores.update_statuses.'.$update->status)));
        $update->fill(array_intersect_key($edits, array_flip(['title', 'body', 'conditions', 'next_steps'])));
        $update->forceFill(['reviewed_by' => auth()->id(), 'reviewed_at' => now(), 'status' => PropertyUpdate::SENT, 'updated_by' => auth()->id()])->save();
        Collaborator::log($update, 'novedad_revisada');
        self::publish($update);

        return $update->fresh();
    }

    public static function discard(PropertyUpdate $update): void
    {
        if ($update->status === PropertyUpdate::PENDING_REVIEW) {
            $update->forceFill(['status' => PropertyUpdate::DISCARDED, 'updated_by' => auth()->id()])->save();
            Collaborator::log($update, 'novedad_descartada');
        }
    }

    /** @return Collection<int, CollaboratorProperty> active assignments the update goes to */
    public static function recipients(PropertyUpdate $update): Collection
    {
        return CollaboratorProperty::query()->active()->with('collaborator')
            ->where('property_id', $update->property_id)->where('team_id', $update->team_id)
            ->when($update->recipients, fn ($q) => $q->whereIn('collaborator_id', $update->recipients))
            ->get()->filter(fn (CollaboratorProperty $a): bool => $a->collaborator?->isEnabled() === true)->values();
    }

    /** @return Collection<int, CollaboratorNotice> */
    private static function publish(PropertyUpdate $update): Collection
    {
        $update->forceFill(['status' => PropertyUpdate::SENT, 'shared_by' => auth()->id(), 'shared_at' => now()])->save();
        $notices = collect();
        foreach (self::recipients($update) as $assignment) {
            self::applyToAssignment($update, $assignment);
            $message = UpdateMessage::render($update->fresh(['property']), $assignment->collaborator, $assignment);
            $notices->push(CollaboratorNotices::send($assignment->collaborator, $update,
                "novedad:{$update->getKey()}:colaborador:{$assignment->collaborator_id}",
                $message['subject'], $message['email'], $message['whatsapp']));
        }
        Collaborator::log($update, 'novedad_compartida', ['avisos' => $notices->pluck('id')->all()]);

        return $notices;
    }

    /** What the collaborator sees follows what has been communicated to them. */
    private static function applyToAssignment(PropertyUpdate $update, CollaboratorProperty $assignment): void
    {
        $changes = match ($update->kind) {
            'precio' => $update->price !== null ? ['authorised_price' => $update->price] : [],
            'disponibilidad' => $update->availability ? ['availability' => $update->availability] : [],
            'visita', 'resultado_visita' => in_array($assignment->commercial_status, [null, 'en_comercializacion'], true) && in_array($update->visit_status, ['programada', 'realizada', null], true) ? ['commercial_status' => 'con_visitas'] : [],
            'oferta' => ['commercial_status' => 'con_oferta'],
            'reserva' => ['commercial_status' => 'reservado', 'availability' => 'reservado'],
            'cierre' => ['commercial_status' => 'cerrado', 'availability' => 'no_disponible'],
            default => [],
        };
        if ($changes !== []) {
            $assignment->forceFill($changes)->save();
        }
    }

    public static function availabilityOf(Property $property): string
    {
        $status = $property->status instanceof PropertyStatus ? $property->status : PropertyStatus::tryFrom((string) $property->status);

        return match ($status) {
            PropertyStatus::UnderOffer, PropertyStatus::LetAgreed, PropertyStatus::SoldStc, PropertyStatus::Sstc => 'reservado',
            PropertyStatus::Sold, PropertyStatus::Let, PropertyStatus::Rented, PropertyStatus::Withdrawn, PropertyStatus::Archive,
            PropertyStatus::Archived, PropertyStatus::Exchanged => 'no_disponible',
            default => 'disponible',
        };
    }

    private static function grantRole(User $user, int $teamId): void
    {
        $roles = config('permission.table_names.roles', 'roles');
        $pivot = config('permission.table_names.model_has_roles', 'model_has_roles');
        $roleId = DB::table($roles)->where('team_id', $teamId)->where('name', CollaboratorAccess::ROLE)->where('guard_name', 'web')->value('id')
            ?? DB::table($roles)->insertGetId(['team_id' => $teamId, 'name' => CollaboratorAccess::ROLE, 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);
        DB::table($pivot)->insertOrIgnore(['role_id' => $roleId, 'model_type' => $user->getMorphClass(), 'model_id' => $user->getKey(), 'team_id' => $teamId]);
        rescue(fn () => app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions(), report: false);
    }

    private static function propertyUrl(Property $property): string
    {
        return rescue(fn () => PropertyResource::getUrl('edit', ['record' => $property], panel: 'admin', tenant: Team::query()->find($property->team_id)), url('/admin'), report: false);
    }

    private static function followUpTask(CollaboratorProperty $assignment): void
    {
        rescue(function () use ($assignment): void {
            $collaborator = $assignment->collaborator;
            TarmioTask::sync($assignment, 'colaboracion_seguimiento',
                __('colaboradores.tarmio.follow', ['name' => $collaborator->name, 'ref' => $assignment->property->reference()]),
                $collaborator->owner?->email ?? auth()->user()?->email, now()->addWeekdays(7), self::propertyUrl($assignment->property));
        }, report: true);
    }

    private static function reviewTask(PropertyUpdate $update): void
    {
        rescue(fn () => TarmioTask::sync($update, 'colaboracion_revision',
            __('colaboradores.tarmio.review', ['kind' => __('colaboradores.update_kinds.'.$update->kind), 'ref' => $update->property->reference()]),
            auth()->user()?->email, now()->addWeekday(), self::propertyUrl($update->property)), report: true);
    }
}
