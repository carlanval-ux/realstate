<?php

declare(strict_types=1);

namespace App\Signing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

/**
 * A «Formulario»: a document template with its signer roles, signature level, source (a DocuSeal template per
 * environment or an uploaded PDF) and fillable fields mapped to owner/property/mandate data.
 *
 * Once a version has been sent (used_at), its content is immutable: only its status can change; a change is a
 * new version (newVersion()).
 */
final class FormTemplate extends Model
{
    public const STATUSES = ['borrador' => 'Draft', 'activo' => 'Active', 'retirado' => 'Withdrawn'];

    public const LEVELS = ['simple' => 'Simple electronic signature', 'avanzada' => 'Advanced electronic signature'];

    public const SOURCES = ['docuseal' => 'DocuSeal template', 'pdf' => 'Uploaded PDF'];

    /** What a used version can no longer change. */
    public const CONTENT = ['key', 'version', 'name', 'signature_level', 'source', 'docuseal_template_ids', 'pdf_path', 'pdf_sha256', 'body', 'roles', 'fields'];

    protected $table = 'clarto_form_templates';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['docuseal_template_ids' => 'array', 'roles' => 'array', 'fields' => 'array', 'fictitious' => 'boolean', 'used_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        self::updating(function (self $template): void {
            if ($template->getOriginal('used_at') !== null && $template->isDirty(self::CONTENT)) {
                throw ValidationException::withMessages(['version' => __('This version has already been sent for signature and cannot be changed. Create a new version.')]);
            }
        });
        self::deleting(function (self $template): void {
            if ($template->used_at !== null) {
                throw ValidationException::withMessages(['version' => __('This version has already been sent for signature and cannot be changed. Create a new version.')]);
            }
        });
    }

    public function requests(): HasMany
    {
        return $this->hasMany(SignatureRequest::class, 'form_template_id');
    }

    public function isLocked(): bool
    {
        return $this->used_at !== null;
    }

    /** A draft copy with the next version number of the same key. */
    public function newVersion(): self
    {
        $copy = $this->replicate(['used_at', 'created_at', 'updated_at']);
        $copy->forceFill(['version' => (int) self::query()->where('key', $this->key)->max('version') + 1, 'status' => 'borrador'])->save();

        return $copy;
    }

    /** DocuSeal template id for an environment (sandbox | production). */
    public function docusealTemplateId(string $environment): ?int
    {
        $id = $this->docuseal_template_ids[$environment] ?? null;

        return is_numeric($id) ? (int) $id : null;
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        return $this->only(array_merge(['id'], self::CONTENT));
    }
}
