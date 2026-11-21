<?php

declare(strict_types=1);

namespace App\SearchRequests;

use App\Collaborators\Collaborator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A collaborator's answer to a request: from the panel (with its kind and proposal data) or a reply by e-mail or
 * WhatsApp linked to the request (kind null until the team classifies it). Always tied to the request and the collaborator.
 */
final class SearchRequestResponse extends Model
{
    public const KINDS = ['encaja', 'puede_buscar', 'aclaraciones', 'no_puede'];

    /** Kinds that bring a property (or the promise of one): what the team compares. */
    public const PROPOSALS = ['encaja', 'puede_buscar'];

    public const STATUSES = ['nueva', 'en_estudio', 'seleccionada', 'descartada'];

    public const MAX_PHOTOS = 10;

    protected $table = 'clarto_search_request_responses';

    protected $attributes = ['status' => 'nueva', 'channel' => 'panel'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['links' => 'array', 'photos' => 'array'];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(SearchRequest::class, 'search_request_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(SearchRequestRecipient::class, 'recipient_id');
    }

    public function collaborator(): BelongsTo
    {
        return $this->belongsTo(Collaborator::class);
    }

    /** The earlier version this one completes (versions are kept: every row keeps its author and date). */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function kindLabel(): string
    {
        return $this->kind ? SearchRequest::label('respuesta', $this->kind) : __('solicitudes.respuesta.sin_clasificar');
    }
}
