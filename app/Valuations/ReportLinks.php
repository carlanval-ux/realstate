<?php

declare(strict_types=1);

namespace App\Valuations;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The ONE generator of public report links (https://valoraciones.clarto.es/r/{codigo}), used by every producer:
 * delivery e-mail and WhatsApp, the GoHighLevel fields and the panel actions.
 *
 * - Code: 16 bytes from the CSPRNG (128 bits) as base64url without padding = 22 characters. Never derived from the
 *   reference, the request id or the client's data.
 * - Stored: only its SHA-256, tied to the request and to the calculation (report version), with expiry, opens and
 *   revocation. The code exists in clear only in the messages that carry it and, briefly, in this process's memory
 *   (so the e-mail, the WhatsApp and GHL of one delivery share one link instead of three).
 * - Revoked by the team → nothing issues a link for that calculation again until the team creates a new one.
 */
final class ReportLinks
{
    public const BYTES = 16;

    public const PATTERN = '[A-Za-z0-9_-]{22}';

    public const EMAIL = 'email';

    public const WHATSAPP = 'whatsapp';

    public const GHL = 'ghl';

    public const PANEL = 'panel';

    /**
     * Run id → code issued by this process. ponytail: process memory (Apache/queue workers), cleared at 200 entries;
     * under Octane it would outlive the request, harmless (same run only) but move it to a scoped binding then.
     *
     * @var array<int, string>
     */
    private static array $issued = [];

    public static function host(): string
    {
        return (string) config('valoraciones.enlaces.host', 'valoraciones.clarto.es');
    }

    public static function url(string $code): string
    {
        return 'https://'.self::host().'/r/'.$code;
    }

    public static function hash(string $code): string
    {
        return hash('sha256', $code);
    }

    public static function newCode(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(self::BYTES)), '+/', '-_'), '=');
    }

    public static function days(): int
    {
        return max(1, (int) config('valoraciones.informe_dias', 30));
    }

    /**
     * The link of the request's current report for one producer: the one this process already issued for that
     * calculation while it has more than a day left, else a new one. Null: no PDF, or links revoked by the team.
     *
     * @return array{url: string, expires_at: CarbonInterface, link: ReportLink}|null
     */
    public static function issue(ValuationRequest $request, string $origin, ?int $userId = null): ?array
    {
        $run = $request->currentRun;
        if ($run === null || $run->pdf_path === null || ($origin !== self::PANEL && self::blocked($request, $run))) {
            return null;
        }
        $code = self::$issued[$run->getKey()] ?? null;
        $link = $code !== null ? ReportLink::query()->where('code_hash', self::hash($code))->first() : null;
        if ($link === null || $link->state() !== 'activo' || $link->expires_at->lte(now()->addDay())) {
            $code = self::newCode();
            $link = ReportLink::query()->create([
                'valuation_request_id' => $request->getKey(), 'valuation_run_id' => $run->getKey(), 'code_hash' => self::hash($code),
                'origin' => $origin, 'expires_at' => now()->addDays(self::days()), 'created_by' => $userId,
            ]);
            if (count(self::$issued) >= 200) {
                self::$issued = [];
            }
            self::$issued[$run->getKey()] = $code;
        }

        return ['url' => self::url($code), 'expires_at' => $link->expires_at, 'link' => $link];
    }

    /**
     * GoHighLevel's informe_url / informe_caduca: null = leave the fields as they are (GHL already holds a live link
     * of this calculation, written by an earlier sync), '' = clear them (no report, or links revoked).
     *
     * @return array{url: string, caduca: string, link: ?ReportLink}|null
     */
    public static function forGhl(ValuationRequest $request): ?array
    {
        $run = $request->currentRun;
        $clear = ['url' => '', 'caduca' => '', 'link' => null];
        if ($run === null || $run->pdf_path === null || self::blocked($request, $run)) {
            return $clear;
        }
        $inGhl = ReportLink::query()->where('valuation_run_id', $run->getKey())->whereNotNull('ghl_at')->whereNull('revoked_at')
            ->where('expires_at', '>', now()->addDay())->exists();
        if ($inGhl && ! isset(self::$issued[$run->getKey()])) {
            return null;
        }
        $issued = self::issue($request, self::GHL);

        return $issued === null ? $clear : ['url' => $issued['url'], 'caduca' => $issued['expires_at']->format('Y-m-d'), 'link' => $issued['link']];
    }

    /**
     * @return array{estado: string, link: ?ReportLink} estado: activo | caducado | revocado | desconocido
     */
    public static function resolve(string $code): array
    {
        if (! preg_match('/^'.self::PATTERN.'$/', $code)) {
            return ['estado' => 'desconocido', 'link' => null];
        }
        $link = ReportLink::query()->where('code_hash', self::hash($code))->first();

        return ['estado' => $link?->state() ?? 'desconocido', 'link' => $link];
    }

    /** Revokes every short link of the request and the signed links sent before them. Returns how many were active. */
    public static function revoke(ValuationRequest $request, ?int $userId): int
    {
        self::$issued = [];

        return DB::transaction(function () use ($request, $userId): int {
            $request->forceFill(['report_links_revoked_at' => now()])->save();

            return ReportLink::query()->where('valuation_request_id', $request->getKey())->whereNull('revoked_at')
                ->update(['revoked_at' => now(), 'revoked_by' => $userId, 'updated_at' => now()]);
        });
    }

    /**
     * Panel «Nuevo enlace del informe»: revokes the previous links and issues a new one for the current report.
     *
     * @return array{url: string, expires_at: CarbonInterface, link: ReportLink}|null
     */
    public static function regenerate(ValuationRequest $request, ?int $userId): ?array
    {
        self::revoke($request, $userId);

        return self::issue($request->refresh(), self::PANEL, $userId);
    }

    /** For logs and the communications register: «…/r/AbCd…» instead of the whole code. */
    public static function redact(string $text): string
    {
        return (string) preg_replace('#/r/([A-Za-z0-9_-]{4})[A-Za-z0-9_-]{18}(?![A-Za-z0-9_-])#', '/r/$1…', $text);
    }

    /**
     * Revoked by the team: no producer issues a link for that calculation again, until the team creates a new one
     * (or a later recalculation). A calculation made after the revocation is not affected.
     */
    private static function blocked(ValuationRequest $request, ValuationRun $run): bool
    {
        $revokedAt = $request->report_links_revoked_at;
        if ($revokedAt === null) {
            return false;
        }
        if ($run->created_at !== null && $run->created_at->gt($revokedAt)) {
            return false;
        }

        return ! ReportLink::query()->where('valuation_run_id', $run->getKey())->whereNull('revoked_at')->exists();
    }

    /** Tests only: forget the codes this process issued (as a new worker would). */
    public static function forget(): void
    {
        self::$issued = [];
    }
}
