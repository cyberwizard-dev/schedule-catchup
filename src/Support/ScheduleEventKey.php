<?php

namespace Cyberwizard\ScheduleCatchup\Support;

use DateTimeZone;

class ScheduleEventKey
{
    /** Length of the ledger's `command` column. */
    public const MAX_LENGTH = 255;

    /**
     * A stable identity for a scheduled event, shared by the catch-up replayer and the
     * normal-run ledger so both address the same row for the same occurrence.
     *
     * The key is `<command>|<cron expression>|<timezone>`, with `|<n>s` appended for
     * sub-minute events, so the same command scheduled twice on different frequencies keeps
     * two separate ledgers. Keys longer than the column are shortened to a prefix plus a hash.
     */
    public static function for(object $event): string
    {
        $parts = [static::legacy($event), static::expression($event), static::timezone($event)];

        $repeatSeconds = $event->repeatSeconds ?? null;

        if ($repeatSeconds !== null) {
            $parts[] = ((int) $repeatSeconds).'s';
        }

        $key = implode('|', $parts);

        if (mb_strlen($key) > self::MAX_LENGTH) {
            $key = mb_substr($key, 0, self::MAX_LENGTH - 41).'#'.sha1($key);
        }

        return $key;
    }

    /**
     * The key used before 1.2.3: the command (or name) alone. Ledger rows and cursors written
     * by older versions use it, so the catch-up command still reads it when upgrading.
     */
    public static function legacy(object $event): string
    {
        $key = $event->command ?? ($event->description ?? $event->getSummaryForDisplay());

        if (str_contains($key, 'artisan')) {
            $key = str($key)->after('artisan')->trim("' ")->toString();
        }

        return $key;
    }

    protected static function expression(object $event): string
    {
        return method_exists($event, 'getExpression')
            ? (string) $event->getExpression()
            : (string) ($event->expression ?? '');
    }

    /**
     * An event without its own timezone is evaluated in the default timezone, which Laravel
     * sets from `app.timezone`.
     */
    protected static function timezone(object $event): string
    {
        $timezone = $event->timezone ?? null;

        if ($timezone instanceof DateTimeZone) {
            return $timezone->getName();
        }

        return $timezone ? (string) $timezone : date_default_timezone_get();
    }
}
