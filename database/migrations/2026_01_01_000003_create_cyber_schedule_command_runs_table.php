<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table('schedule_command_runs'), function (Blueprint $table): void {
            $table->id();
            $table->string('command');
            $table->string('period_key');
            $table->dateTime('ran_at');
            $table->timestamps();

            $table->unique(['command', 'period_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('schedule_command_runs'));
    }

    private function table(string $name): string
    {
        return config('schedule-catchup.table_prefix', 'cyber_').$name;
    }
};
