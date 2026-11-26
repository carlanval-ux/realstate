<?php

declare(strict_types=1);

namespace App\Documents;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * One annotation in the thread of an expediente of «Documentación de la empresa»: date, author, text and at most one
 * attachment (same checks, disk, controller and access log as the expediente's files). Never edited; only its author
 * deletes it, and only within DELETE_MINUTES.
 *
 * @property array{path: string, name: string, mime: string, size: int}|null $file
 */
final class CompanyDocumentAnnotation extends Model
{
    public const UPDATED_AT = null;

    public const DELETE_MINUTES = 15;

    public const MAX_LENGTH = 5000;

    protected $table = 'clarto_company_document_annotations';

    protected $guarded = ['id'];

    protected $casts = ['file' => 'array', 'created_at' => 'datetime'];

    /** @return BelongsTo<CompanyDocument, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(CompanyDocument::class, 'company_document_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function canBeDeletedBy(?object $user): bool
    {
        return $user instanceof User && (string) $user->getKey() === (string) $this->user_id
            && $this->created_at?->gt(now()->subMinutes(self::DELETE_MINUTES)) === true
            && CompanyDocument::canAccess($user, $this->document?->team_id);
    }

    public function deleteBy(User $by): void
    {
        if (! $this->canBeDeletedBy($by)) {
            throw ValidationException::withMessages(['annotation' => __('documentacion.errors.annotation_delete', ['minutes' => self::DELETE_MINUTES])]);
        }
        $this->delete();
    }

    protected static function booted(): void
    {
        self::deleted(function (self $annotation): void {
            if (filled($annotation->file['path'] ?? null)) {
                Storage::disk(CompanyDocument::DISK)->delete($annotation->file['path']);
                if (($document = $annotation->document) !== null) {
                    CompanyDocumentPreview::forget($document, $annotation->file['path']);
                }
            }
        });
    }
}
