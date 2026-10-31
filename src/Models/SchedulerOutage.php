<?php

namespace Cyberwizard\ScheduleCatchup\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * A stretch when the scheduler did not tick: from the last tick before the gap
 * to the run that noticed it.
 */
class SchedulerOutage extends Model
{
    protected $fillable = ['started_at', 'ended_at', 'minutes'];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'minutes' => 'integer',
    ];

    public function getTable(): string
    {
        return config('schedule-catchup.table_prefix', 'cyber_').'schedule_catchup_outages';
    }

    public static function record(DateTimeInterface $startedAt, DateTimeInterface $endedAt): self
    {
        return static::query()->firstOrCreate(
            ['started_at' => $startedAt->format('Y-m-d H:i:s')],
            [
                'ended_at' => $endedAt->format('Y-m-d H:i:s'),
                'minutes' => (int) floor(($endedAt->getTimestamp() - $startedAt->getTimestamp()) / 60),
            ],
        );
    }

    /**
     * The first outage that overlaps [$from, $to], if any.
     */
    public static function between(DateTimeInterface $from, DateTimeInterface $to): ?self
    {
        return static::query()
            ->where('started_at', '<', $to->format('Y-m-d H:i:s'))
            ->where('ended_at', '>', $from->format('Y-m-d H:i:s'))
            ->orderBy('started_at')
            ->first();
    }
}
