<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table('schedule_catchup_outages'), function (Blueprint $table): void {
            $table->id();
            $table->dateTime('started_at')->unique();
            $table->dateTime('ended_at');
            $table->unsignedInteger('minutes');
            $table->timestamps();

            $table->index('ended_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('schedule_catchup_outages'));
    }

    private function table(string $name): string
    {
        return config('schedule-catchup.table_prefix', 'cyber_').$name;
    }
};
