<?php

declare(strict_types=1);

namespace App\Documents;

use App\Rentals\RentalAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * A file kept on a client (party_id) or a property (property_id), on the private disk. Identity and solvency
 * categories reuse the Alquileres permissions (administrators always hold them). Served only by
 * DocumentDownloadController, to members of the file's team.
 */
final class RecordDocument extends Model
{
    public const DISK = 'local';

    /** Server-side whitelist (checked on the stored bytes, not the browser's claim). */
    public const MIMES = [
        'application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif',
        'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.oasis.opendocument.text', 'application/vnd.oasis.opendocument.spreadsheet',
    ];

    public const MAX_KB = 20 * 1024;

    /** category => permission needed to see it (null: any staff member of the team). */
    public const CLIENT_CATEGORIES = [
        'dni_nie' => RentalAccess::IDENTITY,
        'escritura' => null,
        'contrato' => null,
        'poder' => null,
        'solvencia' => RentalAccess::SOLVENCY,
        'cuenta_bancaria' => RentalAccess::SOLVENCY,
        'otro' => null,
    ];

    public const PROPERTY_CATEGORIES = [
        'nota_simple' => null,
        'certificado_energetico' => null,
        'plano' => null,
        'cedula' => null,
        'ibi' => null,
        'escritura' => null,
        'licencia' => null,
        'comunidad' => null,
        'contrato' => null,
        'otro' => null,
    ];

    protected $table = 'clarto_documents';

    protected $guarded = ['id'];

    /** @return array<string, ?string> */
    public static function categories(string $owner): array
    {
        return $owner === 'party' ? self::CLIENT_CATEGORIES : self::PROPERTY_CATEGORIES;
    }

    public static function restrictedBy(string $category): ?string
    {
        return self::CLIENT_CATEGORIES[$category] ?? null;
    }

    /**
     * Categories this user may not see (to filter lists and refuse downloads): the Alquileres permission
     * (identity / solvency), or the Propietarios rule for clear sensitive data (super administrators, administrators).
     *
     * @return list<string>
     */
    public static function hiddenCategoriesFor(?object $user): array
    {
        if ($user instanceof \Illuminate\Contracts\Auth\Authenticatable && \App\Owners\OwnerProfile::canSeeSensitive($user)) {
            return [];
        }

        return array_keys(array_filter(self::CLIENT_CATEGORIES, fn (?string $permission): bool => $permission !== null && ! RentalAccess::allows($user, $permission)));
    }

    /** Member of the file's team (super administrators: any team). */
    public static function inTeam(?object $user, int|string|null $teamId): bool
    {
        if ($user === null || $teamId === null) {
            return false;
        }
        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return true;
        }

        return method_exists($user, 'allTeams') && collect($user->allTeams())->contains(fn ($team): bool => (string) $team->getKey() === (string) $teamId);
    }

    public function visibleTo(?object $user): bool
    {
        if (! self::inTeam($user, $this->team_id)) {
            return false;
        }
        if (function_exists('setPermissionsTeamId')) {
            setPermissionsTeamId($this->team_id);   // download route runs outside the panel's tenant middleware
        }

        return ! in_array($this->category, self::hiddenCategoriesFor($user), true);
    }

    /** @param Builder<self> $query */
    public function scopeVisibleTo(Builder $query, ?object $user): Builder
    {
        return $query->whereNotIn('category', self::hiddenCategoriesFor($user));
    }

    public function categoryLabel(): string
    {
        return __('documentos.categories.'.$this->category);
    }

    public function downloadUrl(): string
    {
        return route('clarto.documentos.descargar', ['source' => 'archivo', 'id' => $this->getKey()]);
    }

    protected static function booted(): void
    {
        // The row is the only reference to the file: removing one removes the other.
        self::deleted(fn (self $document) => Storage::disk($document->disk)->delete($document->path));
    }
}
