<?php

namespace Database\Factories;

use App\Domain\Drafting\CredentialScope;
use App\Domain\Drafting\DraftRunStatus;
use App\Domain\Drafting\Models\DraftRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * Create inside a workspace: inWorkspace($workspace, fn () => DraftRun::factory()->create()).
 *
 * Runs are created pending. The state methods below put the row into a
 * later state after it exists, writing the columns directly. That shortcut
 * is for tests and seeders only; application code uses the mark methods on
 * DraftRun. Provider and model are test names on purpose: nothing may
 * depend on a real one.
 *
 * @extends Factory<DraftRun>
 */
class DraftRunFactory extends Factory
{
    protected $model = DraftRun::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'requested_by' => User::factory(),
            'provider' => 'fake',
            'model' => 'fake-model',
            'prompt_version' => 'v1',
            'credential_scope' => CredentialScope::Instance,
            'request' => [],
        ];
    }

    public function running(): static
    {
        return $this->inStatus(DraftRunStatus::Running, ['started_at' => now()]);
    }

    public function succeeded(int $inputTokens = 1200, int $outputTokens = 300): static
    {
        return $this->inStatus(DraftRunStatus::Succeeded, [
            'attempts' => 1,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'rationale' => 'It fits the brief.',
            'started_at' => now(),
            'finished_at' => now(),
        ]);
    }

    public function failed(string $reason = 'The provider did not answer.'): static
    {
        return $this->inStatus(DraftRunStatus::Failed, [
            'failure_reason' => $reason,
            'started_at' => now(),
            'finished_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function inStatus(DraftRunStatus $status, array $values): static
    {
        return $this->afterCreating(function (DraftRun $run) use ($status, $values): void {
            DB::table('draft_runs')->where('id', $run->id)->update(['status' => $status->value, ...$values]);

            $run->refresh();
        });
    }
}
