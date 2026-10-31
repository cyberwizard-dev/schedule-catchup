<?php

namespace Cyberwizard\ScheduleCatchup\Traits;

use Closure;
use Cyberwizard\ScheduleCatchup\Models\SchedulerCommandRun;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;

trait RecordsScheduleRun
{
    /**
     * Runs $callback once per command and period. A lock covers the check, the work and the
     * record, so a normal run and a replay that overlap cannot both do the work. Exceptions
     * from the work itself, duplicate-key ones included, reach the caller.
     */
    public function runOnceForPeriod(string $periodKey, Closure $callback): bool
    {
        $lock = Cache::store(config('schedule-catchup.cache_driver'))
            ->lock('schedule-catchup:once:'.sha1(static::class.'|'.$periodKey), 6 * 3600);

        if (! $lock->get()) {
            return false;
        }

        try {
            $existing = SchedulerCommandRun::where('command', static::class)
                ->where('period_key', $periodKey)
                ->exists();

            if ($existing) {
                return false;
            }

            $callback();

            try {
                SchedulerCommandRun::create([
                    'command' => static::class,
                    'period_key' => $periodKey,
                    'ran_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                return false;
            }

            return true;
        } finally {
            $lock->release();
        }
    }
}
