<?php

declare(strict_types=1);

namespace App\SearchRequests;

use App\Valuations\ReportLinks;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Private answer link of one recipient (https://panel…/s/{code}). Codes come from the same generator as the public
 * valuation report links (ReportLinks::newCode: 128-bit CSPRNG, 22 base64url chars, independent of the reference);
 * only their SHA-256 is stored. One link per message sent, so every message the collaborator received keeps working
 * until the request stops accepting answers (closed, expired) or the team revokes that recipient's links.
 */
final class SearchRequestLink extends Model
{
    protected $table = 'clarto_search_request_links';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['revoked_at' => 'datetime', 'last_opened_at' => 'datetime'];
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(SearchRequestRecipient::class, 'recipient_id');
    }

    /** A new link for this recipient: the code, in clear, only goes into the message being composed. */
    public static function issue(SearchRequestRecipient $recipient): string
    {
        $code = ReportLinks::newCode();
        self::query()->create(['recipient_id' => $recipient->getKey(), 'code_hash' => ReportLinks::hash($code)]);

        return self::url($code);
    }

    public static function url(string $code): string
    {
        return rtrim((string) config('solicitudes.link_base', config('app.url')), '/').'/s/'.$code;
    }

    /** @return array{estado: string, link: ?self} estado: activo | revocado | cerrada | retirado | desconocido */
    public static function resolve(string $code): array
    {
        $link = preg_match('/^'.ReportLinks::PATTERN.'$/', $code) ? self::query()->with('recipient.request', 'recipient.collaborator')->where('code_hash', ReportLinks::hash($code))->first() : null;

        return ['estado' => $link?->state() ?? 'desconocido', 'link' => $link];
    }

    public function state(): string
    {
        $recipient = $this->recipient;

        return match (true) {
            $this->revoked_at !== null => 'revocado',
            $recipient === null || $recipient->request === null => 'desconocido',
            $recipient->status === 'retirada' || ! $recipient->collaborator?->isEnabled() => 'retirado',
            ! $recipient->request->acceptsAnswers() => 'cerrada',
            default => 'activo',
        };
    }

    /** Revokes every link of a recipient (team action, or withdrawal). */
    public static function revokeFor(SearchRequestRecipient $recipient, ?int $userId): int
    {
        return self::query()->where('recipient_id', $recipient->getKey())->whereNull('revoked_at')->update(['revoked_at' => now(), 'revoked_by' => $userId, 'updated_at' => now()]);
    }

    /** For logs: «…/s/AbCd…» instead of the whole code. */
    public static function redact(string $text): string
    {
        return (string) preg_replace('#/s/([A-Za-z0-9_-]{4})[A-Za-z0-9_-]{18}(?![A-Za-z0-9_-])#', '/s/$1…', $text);
    }
}
