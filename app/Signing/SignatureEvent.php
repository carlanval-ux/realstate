<?php

declare(strict_types=1);

namespace App\Signing;

use Illuminate\Database\Eloquent\Model;

/** A provider webhook as received (unique event key: a repeated delivery is recognised and not processed again). */
final class SignatureEvent extends Model
{
    protected $table = 'clarto_signature_events';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }
}
