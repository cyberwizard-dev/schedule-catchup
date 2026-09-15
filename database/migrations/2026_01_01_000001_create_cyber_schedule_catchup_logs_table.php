<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create($this->table('schedule_catchup_logs'), function (Blueprint $table): void {
            $table->id();
            $table->string('command');
            $table->string('source', 20)->default('catchup');
            $table->dateTime('scheduled_at');
            $table->dateTime('ran_at');
            $table->integer('exit_code')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['command', 'scheduled_at']);
            $table->index(['command', 'exit_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('schedule_catchup_logs'));
    }

    private function table(string $name): string
    {
        return config('schedule-catchup.table_prefix', 'cyber_').$name;
    }
};
