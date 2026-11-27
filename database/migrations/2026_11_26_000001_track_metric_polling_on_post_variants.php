<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_variants', function (Blueprint $table) {
            // When the platform was last asked for the numbers of this
            // variant, whether or not it gave any. Decides when it is due
            // again. Says nothing about the post itself.
            $table->timestamp('metrics_checked_at')->nullable();

            // So that metric_snapshots can name a variant together with its
            // workspace, like every other tenant table does.
            $table->unique(['id', 'workspace_id']);
        });

        // Picking the published variants of one channel that are due.
        DB::statement(<<<'SQL'
            CREATE INDEX post_variants_published_on_channel
            ON post_variants (channel_id, published_at)
            WHERE external_id IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS post_variants_published_on_channel');

        Schema::table('post_variants', function (Blueprint $table) {
            $table->dropUnique(['id', 'workspace_id']);
            $table->dropColumn('metrics_checked_at');
        });
    }
};
