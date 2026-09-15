<?php

namespace Cyberwizard\ScheduleCatchup\Models;

use Illuminate\Database\Eloquent\Model;

class SchedulerCommandRun extends Model
{
    protected $fillable = ['command', 'period_key', 'ran_at'];

    protected $casts = [
        'ran_at' => 'datetime',
    ];

    public function getTable(): string
    {
        return config('schedule-catchup.table_prefix', 'cyber_').'schedule_command_runs';
    }
}
