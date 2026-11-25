<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pillars', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            // Posts per week this theme should get. Zero: none planned.
            $table->unsignedSmallInteger('weekly_target')->default(0);
            $table->timestamps();

            $table->unique(['workspace_id', 'name']);
            // Target of the composite foreign keys on ideas, draft_runs and posts.
            $table->unique(['id', 'workspace_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pillars');
    }
};
