<?php

declare(strict_types=1);

namespace App\Processes\Assistant;

use App\Models\AiSetting;
use App\Modules\ClartoModules;
use Illuminate\Database\Eloquent\Model;

/** Per-company settings of «Preguntar a CLARTO». No row = defaults (off). */
final class AssistantSettings extends Model
{
    public const MODULE = 'procesos_asistente';

    protected $table = 'clarto_assistant_settings';

    protected $guarded = ['id'];

    protected $casts = ['enabled' => 'boolean', 'max_context_chunks' => 'integer', 'max_context_chars' => 'integer', 'log_retention_days' => 'integer'];

    protected $attributes = ['enabled' => false, 'max_context_chunks' => 6, 'max_context_chars' => 8000, 'log_retention_days' => 180];

    public static function forTeam(int|string $teamId): self
    {
        return self::query()->where('team_id', $teamId)->first() ?? new self(['team_id' => $teamId]);
    }

    /** Module switched on (and configured) AND the company turned the assistant on. */
    public static function activeFor(int|string|null $teamId): bool
    {
        return $teamId !== null && ClartoModules::enabled(self::MODULE) && self::forTeam($teamId)->enabled;
    }

    public function model(): ?string
    {
        return filled($this->model) ? $this->model : AiSetting::current()->writing_model;
    }
}
