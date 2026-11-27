<?php

use App\Domain\Channels\Contracts\ChannelCapabilities;
use App\Domain\Channels\Models\Channel;
use App\Domain\Content\Models\Idea;
use App\Domain\Content\Models\Pillar;
use App\Domain\Drafting\Actions\BuildDraftRequest;
use App\Domain\Drafting\CredentialScope;
use App\Domain\Drafting\DraftGenerators;
use App\Domain\Drafting\DraftingContext;
use App\Domain\Drafting\DraftRequest;
use App\Domain\Drafting\DraftResult;
use App\Domain\Drafting\ExamplePost;
use App\Domain\Drafting\LlmKey;
use App\Domain\Drafting\Models\Brief;
use App\Domain\Drafting\Models\DraftRun;
use App\Domain\Drafting\PlannedAlongside;
use App\Domain\Drafting\PromptRenderer;
use App\Domain\Drafting\TargetChannel;
use App\Domain\Drafting\VoiceRules;
use App\Domain\Scheduling\Models\Slot;
use App\Domain\Workspaces\Models\Workspace;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Fakes\FakeDraftGenerator;

/**
 * Helpers for everything that touches drafting. Loaded for every test by
 * tests/Pest.php, so no test of drafting needs Prism, HTTP or a real key.
 *
 * The names below are deliberately unlike the defaults in
 * config/noongun.php, so a test fails if code has a provider, a model or a
 * price of its own.
 */
const DRAFT_TEST_PROVIDER = 'fake';

const DRAFT_TEST_MODEL = 'fake-model';

const DRAFT_TEST_KEY = 'test-instance-llm-key';

/**
 * Makes "fake" the drafting provider, with a model, an instance key and a
 * price, replaces its generator with a FakeDraftGenerator and returns it,
 * so a test can configure answers and inspect calls.
 *
 * Price: 2.00 per million input tokens and 10.00 per million output tokens,
 * in USD. One default answer of the fake (1200 in, 300 out) costs 0.005400.
 */
function fakeDraftGenerator(): FakeDraftGenerator
{
    config([
        'noongun.drafting.provider' => DRAFT_TEST_PROVIDER,
        'noongun.drafting.max_tokens' => 2000,
        'noongun.drafting.timeout' => 20,
        'noongun.drafting.prompt_version' => 'v1',
        'noongun.drafting.currency' => 'USD',
        'noongun.drafting.providers.'.DRAFT_TEST_PROVIDER => [
            'generator' => FakeDraftGenerator::class,
            'api_key' => DRAFT_TEST_KEY,
            'model' => DRAFT_TEST_MODEL,
        ],
        'noongun.drafting.prices.'.DRAFT_TEST_PROVIDER => [
            DRAFT_TEST_MODEL => ['input' => 2.00, 'output' => 10.00],
        ],
    ]);

    $fake = new FakeDraftGenerator;

    app(DraftGenerators::class)->extend(DRAFT_TEST_PROVIDER, fn () => $fake);

    return $fake;
}

/**
 * A context as DraftGenerators::contextFor() gives it after
 * fakeDraftGenerator(), without touching the database.
 */
function draftingContext(string $promptVersion = 'v1', CredentialScope $scope = CredentialScope::Instance): DraftingContext
{
    return new DraftingContext(
        provider: DRAFT_TEST_PROVIDER,
        model: DRAFT_TEST_MODEL,
        maxTokens: 2000,
        timeoutSeconds: 20,
        promptVersion: $promptVersion,
        key: new LlmKey(DRAFT_TEST_PROVIDER, DRAFT_TEST_KEY, $scope),
    );
}

/**
 * Saves a brief in the workspace as its next and current version.
 *
 * @param  array<string, mixed>  $attributes
 */
function briefIn(Workspace $workspace, array $attributes = []): Brief
{
    return inWorkspace($workspace, fn () => Brief::factory()->withHouseVoice()->create($attributes));
}

/**
 * @param  array<string, mixed>  $attributes
 */
function pillarIn(Workspace $workspace, array $attributes = []): Pillar
{
    return inWorkspace($workspace, fn () => Pillar::factory()->create($attributes));
}

/**
 * @param  array<string, mixed>  $attributes
 */
function ideaIn(Workspace $workspace, ?Pillar $pillar = null, array $attributes = []): Idea
{
    return inWorkspace($workspace, fn () => Idea::factory()->create(['pillar_id' => $pillar?->id, ...$attributes]));
}

/**
 * A slot on the given channel, or on a new one.
 *
 * @param  int  $weekday  ISO weekday, 1 for Monday to 7 for Sunday
 * @param  string  $time  wall-clock time in the workspace timezone, H:i
 */
function slotIn(Workspace $workspace, ?Channel $channel = null, int $weekday = 1, string $time = '09:00'): Slot
{
    return inWorkspace($workspace, function () use ($channel, $weekday, $time): Slot {
        $factory = Slot::factory()->at($weekday, $time);

        return ($channel !== null ? $factory->state(['channel_id' => $channel->id]) : $factory)->create();
    });
}

/**
 * A pending draft run in the workspace, opened the way the application
 * does, asked for by the given user or by a new one.
 *
 * @param  array<string, mixed>  $request
 */
function draftRunIn(Workspace $workspace, ?User $requestedBy = null, ?Pillar $pillar = null, ?Idea $idea = null, array $request = []): DraftRun
{
    return inWorkspace($workspace, fn () => DraftRun::start(
        $requestedBy ?? User::factory()->create(),
        draftingContext(),
        $request,
        Brief::current(),
        $pillar,
        $idea,
    ));
}

/**
 * The request for one post on the given channels, built from the current
 * brief of the workspace (one is saved when there is none). An int makes
 * that many new channels. Needs fakeChannelAdapter() for the capabilities.
 *
 * @param  int|list<Channel>  $channels
 */
function draftRequestIn(Workspace $workspace, int|array $channels = 1, ?Pillar $pillar = null, ?Idea $idea = null, ?string $sourceUrl = null): DraftRequest
{
    return inWorkspace($workspace, function () use ($channels, $pillar, $idea, $sourceUrl): DraftRequest {
        if (is_int($channels)) {
            $channels = array_values(Channel::factory()->count($channels)->create()->all());
        }

        return app(BuildDraftRequest::class)->handle(
            Brief::current() ?? Brief::factory()->withHouseVoice()->create(),
            $channels,
            $pillar,
            $idea,
            $sourceUrl,
        );
    });
}

/**
 * A request that needs no database: channels 1 to $channels on the given
 * platform, each with the given body limit.
 *
 * @param  list<array{text: string, url?: string|null}>  $callsToAction
 */
function plainDraftRequest(int $channels = 1, int $maxBodyLength = 500, string $platform = 'facebook', ?string $sourceUrl = null, array $callsToAction = []): DraftRequest
{
    $targets = [];

    for ($id = 1; $id <= $channels; $id++) {
        $targets[] = new TargetChannel($id, $platform, new ChannelCapabilities($maxBodyLength, linkPreviews: true));
    }

    return new DraftRequest(
        briefTitle: 'Table Bay Coffee',
        briefBody: 'We roast coffee in Cape Town and write about it.',
        voiceRules: new VoiceRules(['No contractions.']),
        channels: $targets,
        callsToAction: $callsToAction,
        sourceUrl: $sourceUrl,
    );
}

/**
 * The bytes of the two prompts for the request when it carries the most a
 * request can: every example the configuration allows for each channel
 * with the longest pillar name, the examples' texts at their limit with
 * four bytes for each character, and the longest list of posts planned
 * alongside. Spelled out here with its own numbers, apart from
 * ProposalCost, which must come to the same.
 */
function largestPromptBytes(DraftRequest $request, string $version): int
{
    $perChannel = (int) config('noongun.drafting.examples.per_channel');
    $channels = count($request->channels);
    $characters = $perChannel > 0 ? min($channels * (int) config('noongun.drafting.examples.max_characters'), 40000) : 0;
    $widest = str_repeat("\u{1F600}", 60);
    $examples = [];

    foreach ($request->channels as $channel) {
        for ($i = 0; $i < $perChannel && $characters > 0; $i++) {
            $examples[] = new ExamplePost($channel->platform, '', $channel->channelId, $widest, true);
        }
    }

    $prompt = app(PromptRenderer::class)->render(
        $request->carrying($examples, array_fill(0, 12, new PlannedAlongside($widest, $widest, $widest))),
        $version,
    );

    return strlen($prompt->system) + strlen($prompt->user) + 4 * $characters;
}

/**
 * A valid result for the request, as the fake generator gives it. Override
 * parts of the raw output to get another one, or an InvalidDraftOutput.
 *
 * @param  array<string, mixed>  $output
 */
function draftResultFor(DraftRequest $request, array $output = [], int $attempts = 1): DraftResult
{
    return DraftResult::fromArray(
        [...FakeDraftGenerator::outputFor($request), ...$output],
        $request,
        FakeDraftGenerator::usage(draftingContext(), $attempts),
    );
}

/**
 * Expects the database to refuse the write. The write runs in a transaction
 * of its own, so the test can go on querying afterwards.
 */
function expectRefusedByDatabase(Closure $write): void
{
    expect(fn () => DB::transaction($write))->toThrow(QueryException::class);
}
