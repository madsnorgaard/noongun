<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_variants', function (Blueprint $table) {
            // The card a platform shows for the link when it builds no
            // preview of its own (LinkedIn). Typed by the author: the app
            // never fetches the linked page to fill these in.
            $table->string('link_title', 400)->nullable();
            $table->text('link_description')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('post_variants', function (Blueprint $table) {
            $table->dropColumn(['link_title', 'link_description']);
        });
    }
};
