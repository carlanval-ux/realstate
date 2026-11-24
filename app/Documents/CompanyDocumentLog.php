<?php

declare(strict_types=1);

namespace App\Documents;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One view or download of a file of «Documentación de la empresa»: who, when, which file, from where. Never edited. */
final class CompanyDocumentLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'clarto_company_document_logs';

    protected $guarded = ['id'];

    protected $casts = ['created_at' => 'datetime'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
