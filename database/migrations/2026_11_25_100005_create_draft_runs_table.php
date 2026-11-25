<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('draft_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', ['pending', 'running', 'succeeded', 'failed'])->default('pending');
            $table->string('provider', 32);
            $table->string('model', 128);
            $table->string('prompt_version', 64);
            // Whose key pays for the run: the instance or the workspace.
            $table->enum('credential_scope', ['instance', 'workspace']);
            // What was asked for.
            $table->unsignedBigInteger('brief_id')->nullable();
            $table->unsignedBigInteger('pillar_id')->nullable();
            $table->unsignedBigInteger('idea_id')->nullable();
            $table->json('request');
            // What came back. Tokens are the sum over every attempt.
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->decimal('cost_estimate', 12, 6)->nullable();
            $table->char('cost_currency', 3)->nullable();
            $table->text('rationale')->nullable();
            $table->text('alt_text')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            // Usage and cost per workspace and period.
            $table->index(['workspace_id', 'created_at']);
            // Target of the composite foreign key on posts.
            $table->unique(['id', 'workspace_id']);
        });

        foreach (['brief' => 'briefs', 'pillar' => 'pillars', 'idea' => 'ideas'] as $column => $table) {
            DB::statement(<<<SQL
                ALTER TABLE draft_runs ADD CONSTRAINT draft_runs_{$column}_foreign
                FOREIGN KEY ({$column}_id, workspace_id) REFERENCES {$table} (id, workspace_id)
                ON DELETE SET NULL ({$column}_id)
            SQL);
        }

        DB::statement(<<<'SQL'
            ALTER TABLE draft_runs ADD CONSTRAINT draft_runs_succeeded_have_usage
            CHECK (status <> 'succeeded' OR (input_tokens IS NOT NULL AND output_tokens IS NOT NULL))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE draft_runs ADD CONSTRAINT draft_runs_failed_say_why
            CHECK ((status = 'failed') = (failure_reason IS NOT NULL))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE draft_runs ADD CONSTRAINT draft_runs_cost_has_currency
            CHECK ((cost_estimate IS NULL) = (cost_currency IS NULL))
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('draft_runs');
    }
};
