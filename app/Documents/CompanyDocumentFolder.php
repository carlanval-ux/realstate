<?php

declare(strict_types=1);

namespace App\Documents;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;
use Liberu\Foundation\Organizations\Models\Team;

/**
 * A folder of «Documentación de la empresa»: a tree per company (parent_id), names editable. A folder with
 * subfolders or documents is never deleted (no cascade).
 */
final class CompanyDocumentFolder extends Model
{
    /** Created once per company, the first time the manager is opened; all of them can be renamed. */
    public const DEFAULTS = [
        'Contratos con proveedores', 'Documentación fiscal', 'Documentación de la empresa', 'Contratos de colaboración',
        'Escrituras', 'Poderes', 'Documentos de identidad de socios',
    ];

    protected $table = 'clarto_company_document_folders';

    protected $guarded = ['id'];

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<self, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<self, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** @return HasMany<CompanyDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(CompanyDocument::class, 'folder_id');
    }

    public static function ensureDefaults(int|string|null $teamId): void
    {
        if ($teamId === null || self::query()->where('team_id', $teamId)->exists()) {
            return;
        }
        foreach (self::DEFAULTS as $name) {
            self::query()->create(['team_id' => $teamId, 'name' => $name]);
        }
    }

    /**
     * Every folder of the company with its full path («Contratos de colaboración / 2026»), sorted by path.
     *
     * @return array<int|string, string>
     */
    public static function options(int|string|null $teamId): array
    {
        $folders = self::query()->where('team_id', $teamId)->get(['id', 'parent_id', 'name'])->keyBy('id');
        $paths = $folders->map(function (self $folder) use ($folders): string {
            $names = [];
            for ($f = $folder, $guard = 0; $f !== null && $guard < 50; $f = $folders->get($f->parent_id), $guard++) {
                array_unshift($names, $f->name);
            }

            return implode(' / ', $names);
        });

        return $paths->sort(SORT_NATURAL | SORT_FLAG_CASE)->all();
    }

    public function path(): string
    {
        return self::options($this->team_id)[$this->getKey()] ?? $this->name;
    }

    /** @return list<int> this folder and every folder below it */
    public function withDescendantIds(): array
    {
        $ids = [(int) $this->getKey()];
        for ($level = $ids; $level !== []; $ids = [...$ids, ...$level]) {
            $level = self::query()->whereIn('parent_id', $level)->pluck('id')->map(fn ($id): int => (int) $id)->diff($ids)->values()->all();
        }

        return $ids;
    }

    public function isEmpty(): bool
    {
        return ! $this->children()->exists() && ! $this->documents()->exists();
    }

    protected static function booted(): void
    {
        self::saving(function (self $folder): void {
            $folder->name = trim((string) $folder->name);
            if ($folder->name === '') {
                throw ValidationException::withMessages(['name' => __('documentacion.errors.folder_name')]);
            }
            if ($folder->parent_id === null) {
                return;
            }
            $parent = self::query()->find($folder->parent_id);
            // Same company, and never inside itself or one of its own subfolders.
            if ($parent === null || (string) $parent->team_id !== (string) $folder->team_id
                || ($folder->exists && in_array((int) $parent->getKey(), $folder->withDescendantIds(), true))) {
                throw ValidationException::withMessages(['parent_id' => __('documentacion.errors.folder_parent')]);
            }
        });
        self::deleting(fn (self $folder): bool => $folder->isEmpty());
    }
}
