<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const METRICS = ['reactions', 'comments', 'shares', 'clicks', 'reach'];

    public function up(): void
    {
        Schema::create('metric_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('post_variant_id');
            // UTC. The numbers were first seen at captured_at and were still
            // the same at checked_at: a row is only added when a number
            // changed, see MetricSnapshot::record().
            $table->timestamp('captured_at');
            $table->timestamp('checked_at');

            // Null: the platform did not give this number. Zero: it said zero.
            foreach (self::METRICS as $metric) {
                $table->bigInteger($metric)->nullable();
            }

            // True on the newest row of a variant, false on its history.
            $table->boolean('is_latest')->default(true);
            $table->timestamps();

            $table->foreign(['post_variant_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('post_variants')->cascadeOnDelete();

            // The numbers of one variant over time.
            $table->index(['post_variant_id', 'captured_at']);
            $table->index('workspace_id');
        });

        // The current numbers of a variant are one row, found by this index.
        DB::statement('CREATE UNIQUE INDEX metric_snapshots_one_latest ON metric_snapshots (post_variant_id) WHERE is_latest');

        foreach (self::METRICS as $metric) {
            DB::statement("ALTER TABLE metric_snapshots ADD CONSTRAINT metric_snapshots_{$metric}_not_negative CHECK ({$metric} >= 0)");
        }

        DB::statement('ALTER TABLE metric_snapshots ADD CONSTRAINT metric_snapshots_checked_after_captured CHECK (checked_at >= captured_at)');

        // A row without any number says nothing and is never stored.
        DB::statement('ALTER TABLE metric_snapshots ADD CONSTRAINT metric_snapshots_has_a_number CHECK (num_nonnulls('.implode(', ', self::METRICS).') > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('metric_snapshots');
    }
};
