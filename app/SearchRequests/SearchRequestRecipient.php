<?php

declare(strict_types=1);

namespace App\SearchRequests;

use App\Collaborators\Collaborator;
use App\Collaborators\CollaboratorNotice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One collaborator a request was (or will be) sent to: individual follow-up. Status changes only on what the
 * collaborator does (answers) or the team does (sends, withdraws): a delivery or read receipt never counts as an answer.
 */
final class SearchRequestRecipient extends Model
{
    public const STATUSES = ['pendiente', 'enviada', 'respondida', 'retirada'];

    protected $table = 'clarto_search_request_recipients';

    protected $attributes = ['status' => 'pendiente'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'responded_at' => 'datetime'];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(SearchRequest::class, 'search_request_id');
    }

    public function collaborator(): BelongsTo
    {
        return $this->belongsTo(Collaborator::class);
    }

    public function responses(): HasMany
    {
        return $this->hasMany(SearchRequestResponse::class, 'recipient_id');
    }

    /** Messages sent to this collaborator about this request (copy of the texts and result per channel). */
    public function notices(): HasMany
    {
        return $this->hasMany(CollaboratorNotice::class, 'collaborator_id', 'collaborator_id')
            ->where('subject_type', (new SearchRequest)->getMorphClass())->where('subject_id', $this->search_request_id);
    }

    /** The collaborator has received it (so may open it in the panel and answer). */
    public function wasSent(): bool
    {
        return in_array($this->status, ['enviada', 'respondida'], true);
    }
}
