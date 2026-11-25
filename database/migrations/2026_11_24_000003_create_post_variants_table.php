<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('post_id');
            $table->unsignedBigInteger('channel_id');
            $table->text('body');
            $table->string('link', 2048)->nullable();
            $table->json('utm')->nullable();
            $table->string('external_id')->nullable();
            $table->string('permalink', 2048)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            // Composite keys: a variant, its post and its channel are always
            // in the same workspace. A channel that has variants cannot be
            // deleted; it is disconnected instead.
            $table->foreign(['post_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('posts')->cascadeOnDelete();
            $table->foreign(['channel_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('channels')->restrictOnDelete();

            // One variant per post and channel: the unit that is published
            // at most once.
            $table->unique(['post_id', 'channel_id']);
            // One platform post is recorded on one variant only. Rows that
            // are not published (null) do not collide.
            $table->unique(['channel_id', 'external_id']);
            $table->index('workspace_id');
        });

        // A variant is published as a whole: external id and time together.
        DB::statement(<<<'SQL'
            ALTER TABLE post_variants ADD CONSTRAINT post_variants_published_together
            CHECK ((external_id IS NULL) = (published_at IS NULL))
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('post_variants');
    }
};
