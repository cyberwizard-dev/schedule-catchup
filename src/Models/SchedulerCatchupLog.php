<?php

namespace Cyberwizard\ScheduleCatchup\Models;

use Illuminate\Database\Eloquent\Model;

class SchedulerCatchupLog extends Model
{
    protected $fillable = [
        'command',
        'source',
        'scheduled_at',
        'ran_at',
        'exit_code',
        'attempts',
        'last_error',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'ran_at' => 'datetime',
        'attempts' => 'integer',
    ];

    public function getTable(): string
    {
        return config('schedule-catchup.table_prefix', 'cyber_').'schedule_catchup_logs';
    }

    public function isSettled(int $maxAttempts): bool
    {
        return $this->exit_code === 0 || $this->attempts >= $maxAttempts;
    }
}
