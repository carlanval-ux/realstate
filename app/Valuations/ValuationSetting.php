<?php

declare(strict_types=1);

namespace App\Valuations;

use Illuminate\Database\Eloquent\Model;

/**
 * Single-row provider settings edited in the panel. Client ids and secrets are encrypted at rest (APP_KEY) and never
 * shown back; a blank panel value falls back to the server's .env (config/valoraciones.php).
 */
final class ValuationSetting extends Model
{
    public const PROVIDERS = ['idealista', 'tinsa'];

    public const DRIVERS = ['off', 'simulado', 'http'];

    protected $table = 'clarto_valuation_settings';

    protected $guarded = ['id'];

    protected $hidden = ['idealista_client_id', 'idealista_secret', 'tinsa_client_id', 'tinsa_secret'];

    protected function casts(): array
    {
        return [
            'idealista_client_id' => 'encrypted', 'idealista_secret' => 'encrypted',
            'tinsa_client_id' => 'encrypted', 'tinsa_secret' => 'encrypted',
        ];
    }

    public static function current(): self
    {
        return self::query()->first() ?? new self;
    }

    /** «Formato del informe» chosen in the module (ValuationReport::FORMATS), «portada» by default. */
    public function reportFormat(): string
    {
        return array_key_exists((string) $this->report_format, ValuationReport::FORMATS) ? (string) $this->report_format : ValuationReport::DEFAULT_FORMAT;
    }

    /**
     * Effective configuration of one provider: panel value, else .env.
     *
     * @return array{nombre: string, driver: string, url: ?string, token_url: ?string, client_id: ?string, secret: ?string}
     */
    public function provider(string $key): array
    {
        $env = (array) config("valoraciones.proveedores.{$key}");
        $pick = fn (string $field): ?string => filled($this->getAttribute("{$key}_{$field}")) ? (string) $this->getAttribute("{$key}_{$field}") : (filled($env[$field] ?? null) ? (string) $env[$field] : null);
        $driver = $pick('driver') ?? 'off';

        return [
            'nombre' => (string) ($env['nombre'] ?? $key),
            'driver' => in_array($driver, self::DRIVERS, true) ? $driver : 'off',
            'url' => $pick('url'), 'token_url' => $pick('token_url'), 'client_id' => $pick('client_id'), 'secret' => $pick('secret'),
        ];
    }

    /** What an active HTTP provider is missing (names, never values), or null. */
    public function missing(string $key): ?string
    {
        $p = $this->provider($key);
        if ($p['driver'] !== 'http') {
            return null;
        }
        $missing = array_keys(array_filter(['url' => $p['url'], 'secret' => $p['secret']], 'blank'));

        return $missing === [] ? null : $p['nombre'].': '.implode(', ', $missing);
    }
}
