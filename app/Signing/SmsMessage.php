<?php

declare(strict_types=1);

namespace App\Signing;

use Illuminate\Database\Eloquent\Model;

/** An individual SMS (signing link or manual reminder). The link itself is masked in `text`. */
final class SmsMessage extends Model
{
    protected $table = 'clarto_sms_messages';

    protected $guarded = ['id'];
}
