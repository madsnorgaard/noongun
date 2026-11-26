<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_variants', function (Blueprint $table) {
            // How many times in a row the platform answered that it no
            // longer has this post, when asked for its numbers. Back to
            // zero with the next reading. At noongun.insights.gone_after
            // the variant is not asked about any more.
            $table->unsignedSmallInteger('metrics_gone_count')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('post_variants', function (Blueprint $table) {
            $table->dropColumn('metrics_gone_count');
        });
    }
};
