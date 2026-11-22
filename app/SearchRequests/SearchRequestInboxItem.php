<?php

declare(strict_types=1);

namespace App\SearchRequests;

use App\Collaborators\Collaborator;
use App\Models\Communication;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** «Respuestas pendientes de vincular»: a reply by e-mail or WhatsApp whose request is not certain. */
final class SearchRequestInboxItem extends Model
{
    protected $table = 'clarto_search_request_inbox';

    protected $attributes = ['status' => 'pendiente'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['candidates' => 'array', 'resolved_at' => 'datetime'];
    }

    public function communication(): BelongsTo
    {
        return $this->belongsTo(Communication::class);
    }

    public function collaborator(): BelongsTo
    {
        return $this->belongsTo(Collaborator::class);
    }
}
