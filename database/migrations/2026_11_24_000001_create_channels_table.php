<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 32);
            $table->string('external_id');
            $table->string('display_name');
            // Encrypted by the model. Null once the channel is disconnected.
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->json('scopes');
            $table->enum('status', ['active', 'needs_reconnect', 'disconnected'])->default('active');
            $table->timestamps();

            // One row per connected account in a workspace. Connecting again
            // updates the row.
            $table->unique(['workspace_id', 'platform', 'external_id']);
            // Target of the composite foreign key on post_variants.
            $table->unique(['id', 'workspace_id']);
            // Token expiry checks.
            $table->index(['status', 'token_expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channels');
    }
};
