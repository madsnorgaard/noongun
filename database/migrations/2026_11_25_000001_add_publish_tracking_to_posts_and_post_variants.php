<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            // One round per claim by the scheduler. A publishing job names
            // the round it was queued for.
            $table->unsignedInteger('publish_round')->default(0);
            // How many runs of the publishing job have started in the
            // current round. Each run takes the next number with one
            // conditional UPDATE, so the same queued job can never run twice.
            $table->unsignedInteger('publish_step')->default(0);
        });

        Schema::table('post_variants', function (Blueprint $table) {
            // Set just before a platform is called and cleared only when the
            // platform certainly made no post, or the result is recorded.
            // While it is set on an unpublished variant, the post may be
            // public without the app knowing.
            $table->timestamp('outcome_unknown_since')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('post_variants', function (Blueprint $table) {
            $table->dropColumn('outcome_unknown_since');
        });

        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn(['publish_round', 'publish_step']);
        });
    }
};
