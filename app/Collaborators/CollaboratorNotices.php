<?php

declare(strict_types=1);

namespace App\Collaborators;

use App\Communications\Communications;
use App\Models\Communication;
use App\Modules\ClartoModules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Avisos a colaboradores por correo y WhatsApp, a través del registro único de comunicaciones
 * (App\Communications\Communications: buzón del panel y línea WAHA). Reglas:
 *
 * - Un aviso por dedupe_key: llamar dos veces a send() con la misma clave no crea ni envía otro.
 * - Cada canal (y cada bloque de WhatsApp) se envía una sola vez: un reintento solo repite lo que falló.
 * - Justo antes de enviar se comprueba de nuevo CollaboratorAccess::canSee(colaborador, asunto); sin acceso,
 *   el aviso se cancela y no sale nada.
 */
final class CollaboratorNotices
{
    /**
     * @param  list<string>  $whatsappBlocks  mensajes en orden (uno basta; varios si el contenido debe dividirse)
     */
    public static function send(Collaborator $collaborator, Model $subject, string $key, string $emailSubject, string $emailBody, array $whatsappBlocks, ?int $userId = null): CollaboratorNotice
    {
        $notice = CollaboratorNotice::query()->firstOrCreate(['dedupe_key' => $key], [
            'team_id' => $collaborator->team_id,
            'collaborator_id' => $collaborator->getKey(),
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'email_subject' => $emailSubject,
            'email_body' => $emailBody,
            'whatsapp_blocks' => array_values(array_filter($whatsappBlocks, 'filled')),
            'status' => CollaboratorNotice::PENDING,
            'created_by' => $userId ?? auth()->id(),
        ]);

        return self::dispatch($notice);
    }

    /** Sends what is still pending of this notice (first attempt or retry). */
    public static function dispatch(CollaboratorNotice $notice): CollaboratorNotice
    {
        return Cache::lock('colaborador-aviso:'.$notice->getKey(), 60)->block(20, function () use ($notice): CollaboratorNotice {
            $notice->refresh();
            if (! $notice->isOpen()) {
                return $notice;
            }
            $collaborator = $notice->collaborator;
            $subject = $notice->subject;
            if ($collaborator === null || $subject === null || ! CollaboratorAccess::canSee($collaborator, $subject)) {
                return self::cancel($notice, 'sin_acceso');
            }

            $errors = [];
            $notice->attempts++;
            $notice->email_status = self::channel($notice, 'email', $collaborator->email, [$notice->email_body], $errors);
            $notice->whatsapp_status = self::channel($notice, 'whatsapp', $collaborator->whatsapp, $notice->whatsapp_blocks ?? [], $errors);

            $statuses = [$notice->email_status, $notice->whatsapp_status];
            $notice->status = match (true) {
                ! in_array('error', $statuses, true) && in_array('enviado', $statuses, true) => CollaboratorNotice::SENT,
                in_array('enviado', $statuses, true) => CollaboratorNotice::PARTIAL,
                default => CollaboratorNotice::ERROR,
            };
            if ($notice->status === CollaboratorNotice::ERROR && $errors === []) {
                $errors[] = __('colaboradores.notices.no_channel');
            }
            $notice->last_error = $errors === [] ? null : mb_strimwidth(implode(' · ', $errors), 0, 250, '…');
            $notice->sent_at ??= $notice->status === CollaboratorNotice::SENT ? now() : null;
            $notice->save();

            return $notice;
        });
    }

    public static function cancel(CollaboratorNotice $notice, string $reason): CollaboratorNotice
    {
        if ($notice->isOpen()) {
            $notice->forceFill(['status' => CollaboratorNotice::CANCELLED, 'cancelled_at' => now(), 'cancel_reason' => $reason])->save();
        }

        return $notice;
    }

    /** Cancels every open notice of this collaborator whose subject matches (e.g. the updates of one property). */
    public static function cancelOpen(Collaborator $collaborator, string $subjectType, iterable $subjectIds, string $reason): int
    {
        $n = 0;
        CollaboratorNotice::query()->where('collaborator_id', $collaborator->getKey())->whereIn('status', CollaboratorNotice::OPEN)
            ->where('subject_type', $subjectType)->whereIn('subject_id', collect($subjectIds)->all())
            ->each(function (CollaboratorNotice $notice) use ($reason, &$n): void {
                self::cancel($notice, $reason);
                $n++;
            });

        return $n;
    }

    /** Retries open notices (scheduled). Each one re-checks access first. */
    public static function retryOpen(int $maxAttempts = 5): int
    {
        $n = 0;
        CollaboratorNotice::query()->whereIn('status', CollaboratorNotice::OPEN)->where('attempts', '<', $maxAttempts)
            ->orderBy('id')->limit(200)->get()
            ->each(function (CollaboratorNotice $notice) use (&$n): void {
                rescue(fn () => self::dispatch($notice));
                $n++;
            });

        return $n;
    }

    /**
     * @param  list<string>  $bodies
     * @param  list<string>  $errors
     * @return string enviado | error | sin_destino
     */
    private static function channel(CollaboratorNotice $notice, string $channel, ?string $to, array $bodies, array &$errors): string
    {
        $current = $channel === 'email' ? $notice->email_status : $notice->whatsapp_status;
        if ($current === 'enviado') {
            return $current;
        }
        if (blank($to) || $bodies === []) {
            return 'sin_destino';
        }
        if ($channel === 'whatsapp' && ($refusal = ClartoModules::refusal(ClartoModules::WHATSAPP)) !== null) {
            $errors[] = 'WhatsApp: '.$refusal;

            return 'error';
        }

        foreach (array_values($bodies) as $i => $body) {
            try {
                $c = Communications::send($notice->team_id, null, $channel, (string) $to, (string) $body, [
                    'key' => "colaborador-aviso:{$notice->getKey()}:{$channel}:{$i}",
                    'subject' => $channel === 'email' ? $notice->email_subject : null,
                    'related' => $notice,
                    'source' => 'colaboradores',
                    'user_id' => $notice->created_by,
                ]);
            } catch (Throwable $e) {
                $c = null;
                $errors[] = ($channel === 'email' ? __('colaboradores.channels.email') : 'WhatsApp').': '.$e->getMessage();
            }
            if ($c === null || $c->status !== 'enviado') {
                if ($c instanceof Communication) {
                    $errors[] = ($channel === 'email' ? __('colaboradores.channels.email') : 'WhatsApp').': '.$c->error;
                }

                return 'error';   // later blocks wait for the retry, so the order is kept
            }
        }

        return 'enviado';
    }
}
