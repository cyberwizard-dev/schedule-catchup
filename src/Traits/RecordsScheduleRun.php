<?php

namespace Cyberwizard\ScheduleCatchup\Traits;

use Closure;
use Cyberwizard\ScheduleCatchup\Models\SchedulerCommandRun;
use Illuminate\Database\QueryException;

trait RecordsScheduleRun
{
    public function runOnceForPeriod(string $periodKey, Closure $callback): bool
    {
        $existing = SchedulerCommandRun::where('command', static::class)
            ->where('period_key', $periodKey)
            ->exists();

        if ($existing) {
            return false;
        }

        try {
            $callback();

            SchedulerCommandRun::create([
                'command' => static::class,
                'period_key' => $periodKey,
                'ran_at' => now(),
            ]);

            return true;
        } catch (QueryException $e) {
            if ((int) $e->getCode() !== 23000) {
                throw $e;
            }

            return false;
        }
    }
}
