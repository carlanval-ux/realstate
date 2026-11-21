<?php

declare(strict_types=1);

namespace App\Processes\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Personal reading state (favourite, last view, checked steps by key, «I have read version N»). Only its owner sees it. */
final class ProcessUserState extends Model
{
    protected $table = 'clarto_process_user_states';

    protected $guarded = ['id'];

    protected $casts = ['favorite' => 'boolean', 'checked_steps' => 'array', 'last_viewed_at' => 'datetime', 'read_confirmed_at' => 'datetime'];

    public static function for(User $user, Process $process): self
    {
        return self::query()->firstOrNew(['user_id' => $user->getKey(), 'process_id' => $process->getKey()], ['team_id' => $process->team_id]);
    }
}
