<?php

namespace Cyberwizard\ScheduleCatchup\Models;

use Illuminate\Database\Eloquent\Model;

class SchedulerCatchupCheckpoint extends Model
{
    public const LAST_TICK = 'last_tick';

    protected $fillable = ['key', 'value'];

    public function getTable(): string
    {
        return config('schedule-catchup.table_prefix', 'cyber_').'schedule_catchup_checkpoints';
    }

    public static function get(string $key): ?string
    {
        return static::query()->where('key', $key)->value('value');
    }

    public static function set(string $key, string $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
