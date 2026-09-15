<?php

namespace Cyberwizard\ScheduleCatchup\Tests\Feature;

use Cyberwizard\ScheduleCatchup\Tests\TestCase;
use Illuminate\Support\Facades\Schedule;

class ServiceProviderTest extends TestCase
{
    public function test_it_schedules_the_catchup_and_heartbeat_commands(): void
    {
        $events = collect(Schedule::events());

        $catchup = $events->first(fn ($event) => str_contains((string) $event->command, 'schedule-catchup:run'));
        $heartbeat = $events->first(fn ($event) => str_contains((string) $event->command, 'schedule-catchup:heartbeat'));

        $this->assertNotNull($catchup);
        $this->assertSame('*/5 * * * *', $catchup->getExpression());

        $this->assertNotNull($heartbeat);
        $this->assertSame('*/5 * * * *', $heartbeat->getExpression());
    }
}
