<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('draft_runs', function (Blueprint $table) {
            // The model suggested a link that the author never gave, and it
            // was left out of the draft.
            $table->boolean('link_dropped')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('draft_runs', function (Blueprint $table) {
            $table->dropColumn('link_dropped');
        });
    }
};
