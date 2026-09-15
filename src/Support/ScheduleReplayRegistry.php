<?php

namespace Cyberwizard\ScheduleCatchup\Support;

class ScheduleReplayRegistry
{
    private static ?\WeakMap $tagged = null;

    public static function tag(object $event): void
    {
        self::$tagged ??= new \WeakMap;

        self::$tagged[$event] = true;
    }

    public static function isTagged(object $event): bool
    {
        return self::$tagged !== null && isset(self::$tagged[$event]);
    }
}
