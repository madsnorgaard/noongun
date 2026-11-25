<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ideas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('pillar_id')->nullable();
            $table->string('title');
            $table->string('source_url', 2048)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'pillar_id']);
            // Target of the composite foreign keys on draft_runs and posts.
            $table->unique(['id', 'workspace_id']);
        });

        // An idea and its pillar are always in the same workspace. Deleting
        // the pillar keeps the idea and clears only pillar_id.
        DB::statement(<<<'SQL'
            ALTER TABLE ideas ADD CONSTRAINT ideas_pillar_foreign
            FOREIGN KEY (pillar_id, workspace_id) REFERENCES pillars (id, workspace_id)
            ON DELETE SET NULL (pillar_id)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('ideas');
    }
};
