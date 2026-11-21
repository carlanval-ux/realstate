<?php

declare(strict_types=1);

namespace App\Models;

use App\WhatsApp\Phone;
use App\WhatsApp\Waha;
use Illuminate\Database\Eloquent\Model;

/**
 * The company's WhatsApp line: one WAHA session per team, linked by scanning a QR from the line's phone
 * (Platform settings → WhatsApp). Also keeps the team's reusable message templates.
 */
final class WhatsAppLine extends Model
{
    public const WORKING = 'WORKING';

    public const DEFAULT_TEMPLATES = [
        ['name' => 'Visita', 'body' => "Hola {nombre}, le confirmamos la visita al inmueble {referencia} ({direccion}) el {fecha_visita}.\nSi necesita cambiarla, responda a este mensaje.\n{agente} · {empresa}"],
        ['name' => 'Seguimiento', 'body' => "Hola {nombre}, soy {agente} de {empresa}. Le escribo con novedades sobre su inmueble {referencia}. ¿Le viene bien que hablemos hoy?"],
        ['name' => 'Enlace de firma', 'body' => "Hola {nombre}, le enviamos el enlace para revisar y firmar el documento de su inmueble {referencia}:\n{enlace_firma}\nSi tiene cualquier duda, responda a este mensaje."],
    ];

    /** WAHA session statuses → label (translated) and Filament color. */
    public const STATUSES = [
        'SIN_CREAR' => ['Not linked', 'gray'],
        'STOPPED' => ['Disconnected', 'gray'],
        'STARTING' => ['Starting…', 'warning'],
        'SCAN_QR_CODE' => ['Waiting for the QR scan', 'warning'],
        'WORKING' => ['Connected', 'success'],
        'FAILED' => ['Failed: reconnect', 'danger'],
    ];

    protected $table = 'clarto_whatsapp_lines';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['templates' => 'array', 'status_at' => 'datetime'];
    }

    public static function forTeam(int|string $teamId): self
    {
        // ponytail: WAHA Core has one session ("default"), so one linked team; WAHA Plus + WAHA_SESSION="team-{team}" for more
        return self::query()->firstOrCreate(['team_id' => $teamId], [
            'session' => str_replace('{team}', (string) $teamId, (string) config('whatsapp.session', 'default')),
            'templates' => self::DEFAULT_TEMPLATES,
        ]);
    }

    public function connected(): bool
    {
        return $this->status === self::WORKING;
    }

    public function statusLabel(): string
    {
        return __(self::STATUSES[$this->status][0] ?? $this->status);
    }

    public function statusColor(): string
    {
        return self::STATUSES[$this->status][1] ?? 'gray';
    }

    /** Reads the session from WAHA and stores status and connected number. */
    public function refreshFromWaha(?Waha $waha = null): self
    {
        $session = ($waha ?? new Waha)->session($this->session);

        return $this->applyStatus($session['status'] ?? 'SIN_CREAR', $session['me'] ?? null);
    }

    /** @param array<string, mixed>|null $me */
    public function applyStatus(string $status, ?array $me): self
    {
        $this->fill(['status' => $status, 'status_at' => now()]);
        if ($status === self::WORKING && is_array($me)) {
            $this->fill(['phone' => Phone::fromChatId((string) ($me['id'] ?? '')) ?? $this->phone, 'push_name' => $me['pushName'] ?? $this->push_name]);
        }
        $this->save();

        return $this;
    }

    /** @return array<string, string> template name => body */
    public function templateOptions(): array
    {
        return collect($this->templates ?? [])->filter(fn ($t) => filled($t['name'] ?? null))->mapWithKeys(fn ($t) => [$t['name'] => (string) ($t['body'] ?? '')])->all();
    }
}
