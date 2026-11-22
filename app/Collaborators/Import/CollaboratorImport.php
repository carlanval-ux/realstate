<?php

declare(strict_types=1);

namespace App\Collaborators\Import;

use App\Collaborators\Collaborator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * «Importación de inmuebles» settings of one collaborator (feed or website) and the result of the last run.
 * Nothing is ever imported while authorized_at is empty: copying a partner's catalogue and photos needs their
 * written permission (see CollaboratorImports::run, the single entry point).
 *
 * @property int $id
 * @property int $team_id
 * @property int $collaborator_id
 * @property string $source ninguno|feed|web
 * @property ?string $feed_url
 * @property ?string $feed_format kyero|xml|json
 * @property ?array<string, string> $feed_mapping
 * @property ?string $web_url
 * @property ?string $web_pattern
 * @property string $schedule diario|manual
 * @property ?\Illuminate\Support\Carbon $authorized_at
 * @property ?string $authorization_path
 * @property ?\Illuminate\Support\Carbon $last_run_at
 * @property ?string $last_status
 * @property ?array<string, int> $last_counts
 * @property ?string $last_message
 */
final class CollaboratorImport extends Model
{
    public const NONE = 'ninguno';

    public const FEED = 'feed';

    public const WEB = 'web';

    public const SOURCES = [self::NONE, self::FEED, self::WEB];

    public const FORMATS = ['kyero', 'xml', 'json'];

    public const SCHEDULES = ['diario', 'manual'];

    /** Fields a generic XML/JSON feed can map (field => path inside each listing). «elementos» = where the listings are. */
    public const MAPPABLE = ['elementos', 'referencia', 'precio', 'operacion', 'tipo', 'm2', 'habitaciones', 'banos', 'municipio', 'zona', 'titulo', 'descripcion', 'fotos',
        'caracteristicas', 'planos', 'videos'];

    protected $table = 'clarto_collaborator_imports';

    protected $guarded = ['id'];

    protected $attributes = ['source' => self::NONE, 'schedule' => 'diario'];

    protected function casts(): array
    {
        return ['feed_mapping' => 'array', 'last_counts' => 'array', 'authorized_at' => 'date', 'last_run_at' => 'datetime', 'requested_at' => 'datetime', 'portals_allowed' => 'boolean'];
    }

    /** Settings changes (not run bookkeeping) go to the collaborators' audit log; the authorization date shown in full. */
    protected static function booted(): void
    {
        self::saved(function (self $import): void {
            $fields = array_diff(array_keys($import->getChanges()), ['last_run_at', 'last_status', 'last_counts', 'last_message', 'requested_at', 'requested_by', 'updated_at', 'updated_by']);
            if ($fields !== [] || $import->wasRecentlyCreated) {
                Collaborator::log($import, 'importacion_ajustes', ['colaborador' => $import->collaborator_id, 'campos' => array_values($fields),
                    'autorizacion' => $import->authorized_at?->toDateString(), 'origen' => $import->source]);
            }
        });
    }

    public function collaborator(): BelongsTo
    {
        return $this->belongsTo(Collaborator::class);
    }

    public function isAuthorized(): bool
    {
        return $this->authorized_at !== null;
    }

    public function isConfigured(): bool
    {
        return match ($this->source) {
            self::FEED => filled($this->feed_url) && in_array($this->feed_format, self::FORMATS, true),
            self::WEB => filled($this->web_url),
            default => false,
        };
    }
}
