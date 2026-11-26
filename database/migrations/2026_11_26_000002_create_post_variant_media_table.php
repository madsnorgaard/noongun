<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Target of the composite foreign key below.
        Schema::table('post_variants', function (Blueprint $table) {
            $table->unique(['id', 'workspace_id'], 'post_variants_media_target_unique');
        });

        Schema::create('post_variant_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('post_variant_id');
            $table->unsignedBigInteger('media_id');
            $table->unsignedSmallInteger('position');
            $table->text('alt_text')->nullable();
            // What the platform calls this image once it was sent there for
            // this variant, and when. Kept between the steps of a publish so
            // that a retry does not send the file again.
            $table->string('external_id', 512)->nullable();
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamps();

            // A variant, its images and their files are always in the same
            // workspace. Removing a variant removes its attachments. An image
            // that is attached anywhere cannot be removed. That is checked
            // when the statement ends (NO ACTION), so that deleting a whole
            // workspace, which removes both sides at once, still works.
            $table->foreign(['post_variant_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('post_variants')->cascadeOnDelete();
            $table->foreign(['media_id', 'workspace_id'])->references(['id', 'workspace_id'])->on('media')->noActionOnDelete();

            $table->unique(['post_variant_id', 'media_id']);
            $table->index(['post_variant_id', 'position']);
            $table->index('media_id');
            $table->index('workspace_id');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE post_variant_media ADD CONSTRAINT post_variant_media_uploaded_together
            CHECK ((external_id IS NULL) = (uploaded_at IS NULL))
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('post_variant_media');

        Schema::table('post_variants', function (Blueprint $table) {
            $table->dropUnique('post_variants_media_target_unique');
        });
    }
};
