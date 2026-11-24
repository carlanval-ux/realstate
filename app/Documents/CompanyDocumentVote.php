<?php

declare(strict_types=1);

namespace App\Documents;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One responsable's answer to a document pending approval: conforme (approved) or no conforme, with an optional comment. */
final class CompanyDocumentVote extends Model
{
    protected $table = 'clarto_company_document_votes';

    protected $guarded = ['id'];

    protected $casts = ['approved' => 'boolean'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
