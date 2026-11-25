<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('channel_id');
            // ISO weekday: 1 is Monday, 7 is Sunday.
            $table->unsignedSmallInteger('weekday');
            // Wall-clock time in the workspace timezone, not UTC: "every
            // Tuesday at 09:00" stays 09:00 across daylight saving changes.
            // Slot::occurrenceInWeekOf() turns it into a UTC instant.
            $table->time('time');
            $table->timestamps();

            $table->foreign(['channel_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('channels')->cascadeOnDelete();
            $table->unique(['workspace_id', 'channel_id', 'weekday', 'time']);
        });

        DB::statement('ALTER TABLE slots ADD CONSTRAINT slots_weekday_is_iso CHECK (weekday BETWEEN 1 AND 7)');
    }

    public function down(): void
    {
        Schema::dropIfExists('slots');
    }
};
