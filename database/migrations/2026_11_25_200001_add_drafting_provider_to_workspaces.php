<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            // The LLM provider the owner chose for drafting. Null follows
            // the default of the instance. See docs/adr/0013-provider-per-workspace.md.
            $table->string('drafting_provider', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn('drafting_provider');
        });
    }
};
