<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds the "pending" channel status: an account that a connect returned and
 * the user has not confirmed yet. The status column is a string with a check
 * constraint, so the constraint is replaced.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->allow(['active', 'pending', 'needs_reconnect', 'disconnected']);
    }

    public function down(): void
    {
        // A pending channel was never confirmed. Without the status it can
        // only become disconnected, which erases what it holds.
        DB::table('channels')->where('status', 'pending')->update([
            'status' => 'disconnected',
            'access_token' => null,
            'refresh_token' => null,
            'token_expires_at' => null,
        ]);

        $this->allow(['active', 'needs_reconnect', 'disconnected']);
    }

    /**
     * @param  list<string>  $statuses
     */
    private function allow(array $statuses): void
    {
        $list = implode(', ', array_map(fn (string $status): string => "'{$status}'", $statuses));

        DB::statement('ALTER TABLE channels DROP CONSTRAINT IF EXISTS channels_status_check');
        DB::statement("ALTER TABLE channels ADD CONSTRAINT channels_status_check CHECK (status IN ({$list}))");
    }
};
