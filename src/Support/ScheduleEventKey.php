<?php

namespace Cyberwizard\ScheduleCatchup\Support;

class ScheduleEventKey
{
    /**
     * A stable identity for a scheduled event, shared by the catch-up replayer and the
     * normal-run ledger so both address the same row for the same occurrence.
     */
    public static function for(object $event): string
    {
        $key = $event->command ?? ($event->description ?? $event->getSummaryForDisplay());

        if (str_contains($key, 'artisan')) {
            $key = str($key)->after('artisan')->trim("' ")->toString();
        }

        return $key;
    }
}
