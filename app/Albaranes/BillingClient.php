<?php

declare(strict_types=1);

namespace App\Albaranes;

use Illuminate\Database\Eloquent\Model;

/** Link between an owner (Party) and their client record in the billing program: one per owner, reused. */
final class BillingClient extends Model
{
    protected $table = 'clarto_billing_clients';

    protected $guarded = ['id'];
}
