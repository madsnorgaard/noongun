<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('llm_credentials', function (Blueprint $table) {
            $table->id();
            // Null on an instance key, which belongs to the operator and to
            // no workspace. See docs/adr/0011-drafting-contract.md.
            $table->foreignId('workspace_id')->nullable()->constrained()->cascadeOnDelete();
            $table->enum('scope', ['instance', 'workspace'])->default('workspace');
            $table->string('provider', 32);
            // Encrypted by the model.
            $table->text('api_key');
            $table->timestamps();

            $table->unique(['workspace_id', 'provider']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE llm_credentials ADD CONSTRAINT llm_credentials_scope_matches_workspace
            CHECK ((scope = 'workspace') = (workspace_id IS NOT NULL))
        SQL);

        DB::statement("CREATE UNIQUE INDEX llm_credentials_one_instance_key_per_provider ON llm_credentials (provider) WHERE scope = 'instance'");
    }

    public function down(): void
    {
        Schema::dropIfExists('llm_credentials');
    }
};
