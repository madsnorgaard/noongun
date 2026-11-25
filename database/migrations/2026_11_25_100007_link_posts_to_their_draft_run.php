<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LINKS = ['draft_run' => 'draft_runs', 'pillar' => 'pillars', 'idea' => 'ideas'];

    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            // Where a generated post came from. All three stay empty on a
            // post that was written by hand.
            $table->unsignedBigInteger('draft_run_id')->nullable();
            $table->unsignedBigInteger('pillar_id')->nullable();
            $table->unsignedBigInteger('idea_id')->nullable();

            $table->index(['workspace_id', 'pillar_id']);
            $table->index('draft_run_id');
            $table->index('idea_id');
        });

        foreach (self::LINKS as $column => $table) {
            DB::statement(<<<SQL
                ALTER TABLE posts ADD CONSTRAINT posts_{$column}_foreign
                FOREIGN KEY ({$column}_id, workspace_id) REFERENCES {$table} (id, workspace_id)
                ON DELETE SET NULL ({$column}_id)
            SQL);
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::LINKS) as $column) {
            DB::statement("ALTER TABLE posts DROP CONSTRAINT posts_{$column}_foreign");
        }

        Schema::table('posts', function (Blueprint $table) {
            $table->dropIndex(['workspace_id', 'pillar_id']);
            $table->dropIndex(['draft_run_id']);
            $table->dropIndex(['idea_id']);
            $table->dropColumn(['draft_run_id', 'pillar_id', 'idea_id']);
        });
    }
};
