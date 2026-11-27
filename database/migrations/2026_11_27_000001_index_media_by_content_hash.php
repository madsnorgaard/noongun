<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every upload asks whether its workspace already holds the same
        // image.
        Schema::table('media', function (Blueprint $table) {
            $table->index(['workspace_id', 'content_hash']);
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->dropIndex(['workspace_id', 'content_hash']);
        });
    }
};
