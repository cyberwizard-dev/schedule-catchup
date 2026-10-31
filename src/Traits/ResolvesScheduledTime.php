<?php

namespace Cyberwizard\ScheduleCatchup\Traits;

use Carbon\Carbon;
use Cyberwizard\ScheduleCatchup\Models\SchedulerOutage;
use DateTimeInterface;
use Illuminate\Support\Facades\Date;

trait ResolvesScheduledTime
{
    /**
     * The slot this run stands in for. During a catch-up replay that is the missed
     * occurrence rather than wall-clock now, so month and day maths lands in the
     * period the run was meant to cover instead of the period it happens to run in.
     */
    protected function scheduledFor(): Carbon
    {
        $slot = $this->scheduledSlot();

        return $slot ? Date::parse($slot) : Date::now();
    }

    protected function isCatchupReplay(): bool
    {
        return $this->scheduledSlot() !== null;
    }

    /**
     * The first recorded scheduler outage overlapping [$from, $to], if any. Lets a command
     * decline work, such as a penalty, for a period nobody could clock in for.
     */
    protected function outageDuring(DateTimeInterface $from, DateTimeInterface $to): ?SchedulerOutage
    {
        return SchedulerOutage::between($from, $to);
    }

    private function scheduledSlot(): ?string
    {
        $slot = env('SCHEDULED_EVENT_TIME') ?: ($_ENV['SCHEDULED_EVENT_TIME'] ?? null);

        return $slot ? (string) $slot : null;
    }
}
