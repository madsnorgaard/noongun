<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->enum('state', ['draft', 'approved', 'scheduled', 'publishing', 'published', 'failed', 'rejected'])->default('draft');
            $table->timestamp('scheduled_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            // The scheduler's "due posts" query, and the queue and calendar
            // listings by state and time.
            $table->index(['workspace_id', 'state', 'scheduled_at']);
            // Target of the composite foreign key on post_variants.
            $table->unique(['id', 'workspace_id']);
        });

        // Nothing is on its way to a platform without a recorded approval.
        // approved_by may become null when the approving user is deleted;
        // approved_at stays.
        DB::statement(<<<'SQL'
            ALTER TABLE posts ADD CONSTRAINT posts_approved_before_publishing
            CHECK (state IN ('draft', 'rejected') OR approved_at IS NOT NULL)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE posts ADD CONSTRAINT posts_unapproved_carry_no_approval
            CHECK (state NOT IN ('draft', 'rejected') OR (approved_at IS NULL AND approved_by IS NULL))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE posts ADD CONSTRAINT posts_scheduled_have_a_time
            CHECK (state NOT IN ('scheduled', 'publishing') OR scheduled_at IS NOT NULL)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('posts');
    }
};
