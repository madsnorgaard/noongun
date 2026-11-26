<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            // Where the file is: the name of a disk and a path on it, never
            // an address and never a path on a machine (ADR 0003).
            $table->string('disk', 64);
            $table->string('path', 255)->unique();
            $table->string('thumb_path', 255)->nullable();
            // All of these describe the stored file, which the application
            // wrote itself. Nothing here comes from the upload as it was sent.
            $table->string('mime', 64);
            $table->unsignedBigInteger('byte_size');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->char('content_hash', 64);
            // Set by the clean-up when it first finds the image on no post,
            // cleared when it finds it in use again.
            $table->timestamp('unreferenced_since')->nullable();
            $table->timestamps();

            $table->index('workspace_id');
            $table->index('unreferenced_since');
            // Target of the composite foreign key on post_variant_media.
            $table->unique(['id', 'workspace_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
