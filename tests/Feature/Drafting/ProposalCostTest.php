<?php

use App\Domain\Drafting\Actions\BuildDraftRequest;
use App\Domain\Drafting\DraftGenerators;
use App\Domain\Drafting\DraftSchema;
use App\Domain\Drafting\GenerateDraftJob;
use App\Domain\Drafting\Models\Brief;
use App\Domain\Drafting\Planning\PlannedPost;
use App\Domain\Drafting\Planning\ProposalCost;
use App\Domain\Drafting\PromptRenderer;
use App\Domain\Workspaces\Models\Workspace;
use App\Domain\Workspaces\Role;
use App\Http\Presenters\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../../Support/publishing.php';
require_once __DIR__.'/../../Support/posts.php';
require_once __DIR__.'/../../Support/proposals.php';

beforeEach(function () {
    $this->travelTo('2026-11-25 10:00:00');
    Queue::fake();

    fakeChannelAdapter();
    // 2.00 per million tokens in, 10.00 out; an answer is at most 2000 tokens.
    $this->generator = fakeDraftGenerator();
    $this->workspace = Workspace::factory()->create(['timezone' => 'Europe/Copenhagen']);
    $this->owner = userWithRole($this->workspace, Role::Owner);
    $this->channel = channelIn($this->workspace);
    $this->brief = briefIn($this->workspace);

    foreach ([1, 3, 5] as $weekday) {
        slotIn($this->workspace, $this->channel, $weekday, '09:00');
    }
});

/**
 * @return array{expected: string, worst: string}|null amounts with six places
 */
function proposalCost(Workspace $workspace): ?array
{
    return inWorkspace($workspace, function () use ($workspace): ?array {
        $cost = app(ProposalCost::class)->of(
            planFor($workspace, '2026-11-30'),
            Brief::current(),
            app(DraftGenerators::class)->contextFor($workspace),
        );

        return $cost === null ? null : ['expected' => $cost['expected']->amount, 'worst' => $cost['worst']->amount];
    });
}

/**
 * The bytes of everything one planned post sends: both prompts and the schema.
 */
function promptBytes(Workspace $workspace, PlannedPost $post): int
{
    return inWorkspace($workspace, function () use ($post): int {
        $prompt = app(PromptRenderer::class)->render(
            app(BuildDraftRequest::class)->handle(Brief::current(), $post->channels, $post->pillar, $post->idea),
            'v1',
        );

        return strlen($prompt->system) + strlen($prompt->user) + strlen(json_encode(DraftSchema::definition()));
    });
}

it('expects one answer of the usual length for each post, with the prompt that would be sent', function () {
    config(['noongun.drafting.limits.expected_output_tokens' => 1500]);

    $input = 0;

    foreach (planFor($this->workspace, '2026-11-30')->posts as $post) {
        $input += intdiv(promptBytes($this->workspace, $post), 3) + 1;
    }

    $millionths = (int) round($input * 2.00 + 3 * 1500 * 10.00);

    expect(proposalCost($this->workspace)['expected'])->toBe(sprintf('0.%06d', $millionths));
});

it('follows the configured length of a usual answer, and never expects more than an answer may hold', function () {
    config(['noongun.drafting.limits.expected_output_tokens' => 500]);
    $short = (float) proposalCost($this->workspace)['expected'];

    config(['noongun.drafting.limits.expected_output_tokens' => 1500]);
    $usual = (float) proposalCost($this->workspace)['expected'];

    config(['noongun.drafting.limits.expected_output_tokens' => 2000]);
    $full = (float) proposalCost($this->workspace)['expected'];

    config(['noongun.drafting.limits.expected_output_tokens' => 900000]);
    $absurd = (float) proposalCost($this->workspace)['expected'];

    // 3 posts x 1000 tokens x 10.00 per million.
    expect(round($usual - $short, 6))->toBe(0.03)
        ->and($absurd)->toBe($full);
});

it('gives a worst case that counts a token for every byte, every attempt and two full answers each', function () {
    config(['noongun.drafting.limits.max_attempts' => 3]);

    $bytes = 0;

    foreach (planFor($this->workspace, '2026-11-30')->posts as $post) {
        $bytes += promptBytes($this->workspace, $post) + 1024;
    }

    $answers = 3 * 2;
    $millionths = (int) round($bytes * $answers * 2.00 + 3 * $answers * 2000 * 10.00);

    expect(proposalCost($this->workspace)['worst'])->toBe(sprintf('%d.%06d', intdiv($millionths, 1_000_000), $millionths % 1_000_000));
});

it('cannot be exceeded by a longer brief: the worst case grows with the prompt', function () {
    $before = proposalCost($this->workspace);

    // Multi-byte text, which a tokenizer splits into more tokens than characters.
    briefIn($this->workspace, ['body' => str_repeat('Rødgrød med fløde på blåbærsyltetøj. ', 4000)]);

    $after = proposalCost($this->workspace);
    $bytesAdded = strlen(str_repeat('Rødgrød med fløde på blåbærsyltetøj. ', 4000)) - strlen($this->brief->body);

    // The added bytes, as tokens, in each of 3 posts x 6 answers, at 2.00 per
    // million. The renderer trims a little white space, hence the margin.
    expect((float) $after['worst'] - (float) $before['worst'])->toBeGreaterThanOrEqual(0.999 * $bytesAdded * 18 * 2.00 / 1_000_000)
        ->and((float) $after['expected'])->toBeGreaterThan((float) $before['expected'])
        ->and((float) $after['worst'])->toBeGreaterThan((float) $after['expected']);
});

it('is always above what a run of the week can use at most', function () {
    // The dearest a single run can be: two answers at the token limit, with the prompt counted in bytes.
    $plan = planFor($this->workspace, '2026-11-30');
    $dearestRun = (2 * promptBytes($this->workspace, $plan->posts[0]) * 2.00 + 2 * 2000 * 10.00) / 1_000_000;

    expect((float) proposalCost($this->workspace)['worst'])->toBeGreaterThan(3 * 3 * $dearestRun);
});

it('has no cost without a price, without a plan or without a prompt', function (Closure $arrange) {
    $arrange($this);

    expect(proposalCost($this->workspace))->toBeNull();
})->with([
    'no price for the model' => [fn () => config(['noongun.drafting.prices' => []])],
    'nothing to draft' => [fn () => DB::table('slots')->delete()],
    'a prompt version that does not exist' => [fn () => config(['noongun.drafting.prompt_version' => 'v999'])],
]);

it('sends nothing and stores nothing to work it out', function () {
    proposalCost($this->workspace);

    $this->generator->assertNothingGenerated();

    expect(DB::table('draft_runs')->count())->toBe(0);
});

describe('what was used', function () {
    it('sums the cost and the tokens of every attempt of the proposal on the progress card', function () {
        $runs = proposeWeek($this->workspace, $this->owner);

        $this->actingAs($this->owner)->get(route('queue.index'))
            ->assertInertia(fn (Assert $page) => $page->where('drafting.proposal.used', null));

        // One answer that could not be used after two tries, one failure without an answer, then drafts.
        $this->generator->failInvalid()->failRetryable();
        runQueuedDraftJobs();

        expect(DB::table('draft_runs')->pluck('status')->all())->toBe(['failed', 'failed', 'succeeded', 'succeeded']);

        // 2400 + 1200 + 1200 tokens in, 600 + 300 + 300 out: 0.0108 + 0.0054 + 0.0054.
        $this->get(route('queue.index'))
            ->assertInertia(fn (Assert $page) => $page->where('drafting.proposal.used', [
                'input_tokens' => 4800,
                'output_tokens' => 1200,
                'cost' => '0.0216',
                'currency' => 'USD',
            ]));

        expect($runs)->toHaveCount(3);
    });

    it('gives the tokens without a cost when the model has no price', function () {
        config(['noongun.drafting.prices' => []]);

        $runs = proposeWeek($this->workspace, $this->owner);
        runDraftJob(new GenerateDraftJob($this->workspace->id, $runs[0]->id));

        $this->actingAs($this->owner)->get(route('queue.index'))
            ->assertInertia(fn (Assert $page) => $page->where('drafting.proposal.used', [
                'input_tokens' => 1200,
                'output_tokens' => 300,
                'cost' => null,
                'currency' => null,
            ]));
    });

    it('shows on a drafted post what that one draft used', function () {
        proposeWeek($this->workspace, $this->owner);
        runQueuedDraftJobs();

        $this->actingAs($this->owner)->get(route('posts.show', DB::table('posts')->value('id')))
            ->assertInertia(fn (Assert $page) => $page
                ->where('post.origin.input_tokens', 1200)
                ->where('post.origin.output_tokens', 300)
                ->where('post.origin.cost', '0.0054')
                ->where('post.origin.currency', 'USD'));
    });
});

describe('amounts', function () {
    it('rounds an estimate up to whole cents and never down', function (string $amount, string $shown) {
        expect(Money::upToCents($amount))->toBe($shown);
    })->with([
        ['0.000000', '0.00'],
        ['0.000001', '0.01'],
        ['0.010000', '0.01'],
        ['0.010001', '0.02'],
        ['0.648000', '0.65'],
        ['14.780000', '14.78'],
        ['123.999999', '124.00'],
    ]);

    it('shows what was used to four places', function (string $amount, string $shown) {
        expect(Money::used($amount))->toBe($shown);
    })->with([
        ['0.005400', '0.0054'],
        ['0.000049', '0.0000'],
        ['0.000050', '0.0001'],
        ['2.500000', '2.5000'],
    ]);

    it('adds amounts without a float', function () {
        expect(Money::sum(['0.100000', '0.200000', null, '0.000001']))->toBe('0.300001')
            ->and(Money::sum([]))->toBe('0.000000');
    });
});
