<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('metric_snapshots', function (Blueprint $table) {
            // Metric name to the time since which its number on this row is
            // the last one the platform gave, carried forward because the
            // platform stopped giving it. Null: every number on the row was
            // given at checked_at. See MetricSnapshot::record().
            $table->jsonb('carried')->nullable();
        });

        Schema::table('channels', function (Blueprint $table) {
            // When the platform was last asked whether the token of this
            // channel is still good, whatever it answered. Decides when the
            // channel is checked again.
            $table->timestamp('token_checked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropColumn('token_checked_at');
        });

        Schema::table('metric_snapshots', function (Blueprint $table) {
            $table->dropColumn('carried');
        });
    }
};
