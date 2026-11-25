<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('briefs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            // Saving a brief adds a row. Rows are never edited, so a draft
            // run keeps pointing at the text it was generated from.
            $table->unsignedInteger('version');
            $table->boolean('is_current')->default(false);
            $table->string('title');
            $table->text('body');
            $table->json('voice_rules');
            $table->json('calls_to_action');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['workspace_id', 'version']);
            // Target of the composite foreign key on draft_runs.
            $table->unique(['id', 'workspace_id']);
        });

        // One current version per workspace.
        DB::statement('CREATE UNIQUE INDEX briefs_one_current_per_workspace ON briefs (workspace_id) WHERE is_current');
    }

    public function down(): void
    {
        Schema::dropIfExists('briefs');
    }
};
