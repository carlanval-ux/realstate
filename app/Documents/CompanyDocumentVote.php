<?php

declare(strict_types=1);

namespace App\Documents;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One responsable's answer to a document pending approval: «A favor», «A favor con condiciones» (the conditions, required,
 * are the comment) or «En contra»; the comment is optional otherwise. Both kinds of «a favor» count as approvals.
 */
final class CompanyDocumentVote extends Model
{
    public const FAVOR = 'favor';

    public const CONDITIONS = 'condiciones';

    public const AGAINST = 'contra';

    public const DECISIONS = [self::FAVOR, self::CONDITIONS, self::AGAINST];

    /** The comment, or the conditions of a «con condiciones» vote. */
    public const MAX_COMMENT = 2000;

    protected $table = 'clarto_company_document_votes';

    protected $guarded = ['id'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
