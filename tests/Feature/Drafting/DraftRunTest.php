<?php

use App\Domain\Drafting\CostEstimate;
use App\Domain\Drafting\CredentialScope;
use App\Domain\Drafting\DraftRunStatus;
use App\Domain\Drafting\DraftUsage;
use App\Domain\Drafting\Exceptions\DraftingStateConflict;
use App\Domain\Drafting\Models\DraftRun;
use App\Domain\Workspaces\Models\Workspace;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

itIsolatesWorkspaces(fn () => DraftRun::factory()->create());

beforeEach(function () {
    $this->travelTo('2026-11-25 10:00:00');

    $this->workspace = Workspace::factory()->create();
});

it('opens as pending with who asked, what was asked and what will answer', function () {
    $user = User::factory()->create();
    $brief = briefIn($this->workspace);
    $pillar = pillarIn($this->workspace);
    $idea = ideaIn($this->workspace, $pillar);

    $run = draftRunIn($this->workspace, $user, $pillar, $idea, ['channel_ids' => [4, 5], 'planned_at' => '2026-12-01T08:00:00Z']);
    $run = inWorkspace($this->workspace, fn () => DraftRun::findOrFail($run->id));

    expect($run->status)->toBe(DraftRunStatus::Pending)
        ->and($run->requested_by)->toBe($user->id)
        ->and($run->requester?->is($user))->toBeTrue()
        ->and($run->provider)->toBe(DRAFT_TEST_PROVIDER)
        ->and($run->model)->toBe(DRAFT_TEST_MODEL)
        ->and($run->prompt_version)->toBe('v1')
        ->and($run->credential_scope)->toBe(CredentialScope::Instance)
        ->and($run->brief_id)->toBe($brief->id)
        ->and($run->pillar_id)->toBe($pillar->id)
        ->and($run->idea_id)->toBe($idea->id)
        ->and($run->request)->toBe(['channel_ids' => [4, 5], 'planned_at' => '2026-12-01T08:00:00Z'])
        ->and($run->attempts)->toBe(0)
        ->and($run->input_tokens)->toBeNull()
        ->and($run->cost_estimate)->toBeNull()
        ->and($run->started_at)->toBeNull();
});

it('never stores the key', function () {
    $run = draftRunIn($this->workspace);

    expect(json_encode(DB::table('draft_runs')->where('id', $run->id)->first()))->not->toContain(DRAFT_TEST_KEY);
});

it('records which key pays', function () {
    $run = inWorkspace($this->workspace, fn () => DraftRun::start(User::factory()->create(), draftingContext(scope: CredentialScope::Workspace)));

    expect(DB::table('draft_runs')->where('id', $run->id)->value('credential_scope'))->toBe('workspace');
});

it('keeps its record when the brief, the pillar, the idea or the user is deleted', function () {
    $user = User::factory()->create();
    $brief = briefIn($this->workspace);
    $pillar = pillarIn($this->workspace);
    $idea = ideaIn($this->workspace);
    $run = draftRunIn($this->workspace, $user, $pillar, $idea);

    inWorkspace($this->workspace, function () use ($brief, $pillar, $idea) {
        $idea->delete();
        $pillar->delete();
        $brief->delete();
    });
    $user->delete();

    $row = DB::table('draft_runs')->where('id', $run->id)->first();

    expect([$row->brief_id, $row->pillar_id, $row->idea_id, $row->requested_by])->toBe([null, null, null, null])
        ->and($row->workspace_id)->toBe($this->workspace->id);
});

describe('transitions', function () {
    it('starts once', function () {
        $run = draftRunIn($this->workspace);

        inWorkspace($this->workspace, function () use ($run) {
            $again = DraftRun::findOrFail($run->id);

            expect($run->markRunning())->toBeTrue()
                ->and($run->status)->toBe(DraftRunStatus::Running)
                ->and($run->started_at?->toDateTimeString())->toBe('2026-11-25 10:00:00')
                ->and($again->markRunning())->toBeFalse()
                ->and($again->status)->toBe(DraftRunStatus::Running);
        });
    });

    it('succeeds from running, with usage, cost and what the result said', function () {
        $run = draftRunIn($this->workspace);
        $result = draftResultFor(plainDraftRequest(), ['alt_text' => 'A cup of coffee.'], attempts: 2);

        inWorkspace($this->workspace, function () use ($run, $result) {
            expect($run->markSucceeded($result))->toBeFalse()
                ->and($run->status)->toBe(DraftRunStatus::Pending);

            $run->markRunning();
            $this->travel(4)->seconds();

            expect($run->markSucceeded($result, new CostEstimate('0.010800', 'USD')))->toBeTrue()
                ->and($run->markSucceeded($result))->toBeFalse()
                ->and($run->markFailed('Too late.'))->toBeFalse();
        });

        expect(DB::table('draft_runs')->where('id', $run->id)->first())->toMatchObject([
            'status' => 'succeeded',
            'attempts' => 2,
            'input_tokens' => 2400,
            'output_tokens' => 600,
            'cost_estimate' => '0.010800',
            'cost_currency' => 'USD',
            'rationale' => 'Generated draft 1 fits the brief.',
            'alt_text' => 'A cup of coffee.',
            'failure_reason' => null,
            'finished_at' => '2026-11-25 10:00:04',
        ]);
    });

    it('succeeds without a cost when no price is known', function () {
        $run = draftRunIn($this->workspace);

        inWorkspace($this->workspace, function () use ($run) {
            $run->markRunning();

            expect($run->markSucceeded(draftResultFor(plainDraftRequest())))->toBeTrue()
                ->and($run->input_tokens)->toBe(1200)
                ->and($run->cost_estimate)->toBeNull()
                ->and($run->cost_currency)->toBeNull();
        });
    });

    it('fails from pending or running, with a reason and what it used', function (bool $started) {
        $run = draftRunIn($this->workspace);

        inWorkspace($this->workspace, function () use ($run, $started) {
            if ($started) {
                $run->markRunning();
            }

            $usage = new DraftUsage(DRAFT_TEST_PROVIDER, DRAFT_TEST_MODEL, 2400, 600, 2);

            expect($run->markFailed('The generated draft could not be used.', $usage, new CostEstimate('0.010800', 'USD')))->toBeTrue()
                ->and($run->status)->toBe(DraftRunStatus::Failed)
                ->and($run->status->isFinished())->toBeTrue()
                ->and($run->failure_reason)->toBe('The generated draft could not be used.')
                ->and($run->attempts)->toBe(2)
                ->and($run->input_tokens)->toBe(2400)
                ->and($run->cost_estimate)->toBe('0.010800')
                ->and($run->finished_at)->not->toBeNull()
                ->and($run->markFailed('Again.'))->toBeFalse()
                ->and($run->markRunning())->toBeFalse()
                ->and($run->failure_reason)->toBe('The generated draft could not be used.');
        });
    })->with(['pending' => false, 'running' => true]);

    it('fails without usage when the provider reported none', function () {
        $run = draftRunIn($this->workspace);

        inWorkspace($this->workspace, fn () => $run->markFailed(''));

        expect($run->failure_reason)->toBe('The run failed.')
            ->and($run->input_tokens)->toBeNull()
            ->and($run->attempts)->toBe(0);
    });

    it('cleans and shortens the reason before it is stored', function () {
        $run = draftRunIn($this->workspace);

        inWorkspace($this->workspace, fn () => $run->markFailed('Rejected: x-api-key: abc123def456 '.str_repeat('long ', 400)));

        expect($run->failure_reason)->not->toContain('abc123def456')
            ->and(mb_strlen($run->failure_reason))->toBeLessThanOrEqual(DraftRun::MAX_FAILURE_REASON_LENGTH + 3);
    });

    it('lets one of two processes win', function () {
        $run = draftRunIn($this->workspace);

        inWorkspace($this->workspace, function () use ($run) {
            $run->markRunning();
            $other = DraftRun::findOrFail($run->id);

            expect($other->markFailed('Timed out.'))->toBeTrue()
                ->and($run->markSucceeded(draftResultFor(plainDraftRequest())))->toBeFalse()
                ->and($run->status)->toBe(DraftRunStatus::Failed);
        });
    });
});

it('cannot have its outcome written directly', function (string $attribute, mixed $value) {
    $run = draftRunIn($this->workspace);

    inWorkspace($this->workspace, function () use ($run, $attribute, $value) {
        expect(fn () => $run->forceFill([$attribute => $value])->save())->toThrow(DraftingStateConflict::class);
    });

    expect(DB::table('draft_runs')->where('id', $run->id)->value('status'))->toBe('pending');
})->with([
    'status' => ['status', DraftRunStatus::Succeeded],
    'tokens' => ['input_tokens', 0],
    'cost' => ['cost_estimate', '0.000001'],
    'reason' => ['failure_reason', 'Made up.'],
]);

it('is not mass assignable', function () {
    $run = draftRunIn($this->workspace);

    expect(fn () => $run->fill(['status' => 'succeeded', 'provider' => 'other', 'input_tokens' => 1]))->toThrow(MassAssignmentException::class)
        ->and(fn () => inWorkspace($this->workspace, fn () => DraftRun::query()->create(['provider' => 'other'])))->toThrow(MassAssignmentException::class);

    expect(DB::table('draft_runs')->where('id', $run->id)->first())->toMatchObject(['status' => 'pending', 'provider' => DRAFT_TEST_PROVIDER, 'input_tokens' => null]);
});

describe('the database', function () {
    it('refuses a succeeded run without usage', function () {
        $run = draftRunIn($this->workspace);

        expect(fn () => DB::table('draft_runs')->where('id', $run->id)->update(['status' => 'succeeded']))->toThrow(QueryException::class);
    });

    it('refuses a failed run without a reason, and a reason on a run that did not fail', function () {
        $run = draftRunIn($this->workspace);

        expectRefusedByDatabase(fn () => DB::table('draft_runs')->where('id', $run->id)->update(['status' => 'failed']));
        expectRefusedByDatabase(fn () => DB::table('draft_runs')->where('id', $run->id)->update(['failure_reason' => 'Why.']));
    });

    it('refuses a cost without a currency', function () {
        $run = draftRunIn($this->workspace);

        expect(fn () => DB::table('draft_runs')->where('id', $run->id)->update(['cost_estimate' => '0.01']))->toThrow(QueryException::class);
    });
});

it('has factory states for tests', function () {
    inWorkspace($this->workspace, function () {
        expect(DraftRun::factory()->running()->create()->status)->toBe(DraftRunStatus::Running)
            ->and(DraftRun::factory()->succeeded(10, 5)->create()->output_tokens)->toBe(5)
            ->and(DraftRun::factory()->failed('No.')->create()->failure_reason)->toBe('No.')
            ->and(DraftRun::query()->inStatus(DraftRunStatus::Succeeded, DraftRunStatus::Failed)->count())->toBe(2);
    });
});
