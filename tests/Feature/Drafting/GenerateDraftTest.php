<?php

use App\Domain\Content\Exceptions\PostActionNotAllowed;
use App\Domain\Content\Exceptions\PostNotEditable;
use App\Domain\Content\Models\Post;
use App\Domain\Content\PostState;
use App\Domain\Drafting\Actions\CreateDraftFromResult;
use App\Domain\Drafting\Actions\GenerateDraft;
use App\Domain\Drafting\DraftRunStatus;
use App\Domain\Drafting\Exceptions\DraftGenerationFailed;
use App\Domain\Drafting\Exceptions\DraftGeneratorNotAvailable;
use App\Domain\Drafting\Exceptions\DraftingNotAllowed;
use App\Domain\Drafting\Exceptions\DraftingStateConflict;
use App\Domain\Drafting\Exceptions\InvalidDraftOutput;
use App\Domain\Drafting\Exceptions\LlmCredentialMissing;
use App\Domain\Drafting\Models\DraftRun;
use App\Domain\Drafting\Models\LlmCredential;
use App\Domain\Workspaces\Models\Workspace;
use App\Domain\Workspaces\Role;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../../Support/publishing.php';
require_once __DIR__.'/../../Support/posts.php';

beforeEach(function () {
    $this->travelTo('2026-11-25 10:00:00');

    fakeChannelAdapter();
    $this->generator = fakeDraftGenerator();
    $this->workspace = Workspace::factory()->create(['timezone' => 'Africa/Johannesburg']);
    $this->editor = userWithRole($this->workspace, Role::Editor);
    $this->facebook = channelIn($this->workspace);
    $this->second = channelIn($this->workspace);
});

function runningRun(Workspace $workspace, mixed ...$arguments): DraftRun
{
    $run = draftRunIn($workspace, ...$arguments);

    inWorkspace($workspace, fn () => $run->markRunning());

    return $run;
}

describe('CreateDraftFromResult', function () {
    it('turns a result into a draft post with one variant per channel', function () {
        $pillar = pillarIn($this->workspace);
        $idea = ideaIn($this->workspace, $pillar, ['source_url' => 'https://example.com/roast']);
        $request = draftRequestIn($this->workspace, [$this->facebook, $this->second], $pillar, $idea);
        $run = runningRun($this->workspace, $this->editor, $pillar, $idea);

        $post = inWorkspace($this->workspace, fn () => app(CreateDraftFromResult::class)->handle($this->editor, $run, draftResultFor($request)));

        $variants = variantsOf($post);

        expect(freshPost($post))
            ->state->toBe(PostState::Draft)
            ->approved_at->toBeNull()
            ->approved_by->toBeNull()
            ->scheduled_at->toBeNull()
            ->draft_run_id->toBe($run->id)
            ->pillar_id->toBe($pillar->id)
            ->idea_id->toBe($idea->id)
            ->and($variants)->toHaveCount(2)
            ->and($variants[0]->channel_id)->toBe($this->facebook->id)
            ->and($variants[0]->body)->toBe('Generated draft 1 for facebook.')
            ->and($variants[0]->link)->toBe('https://example.com/roast')
            ->and($variants[1]->channel_id)->toBe($this->second->id)
            ->and($variants[0]->isPublished())->toBeFalse();

        inWorkspace($this->workspace, function () use ($post, $run, $pillar) {
            $post = Post::with(['draftRun', 'pillar', 'idea'])->findOrFail($post->id);

            expect($post->draftRun?->id)->toBe($run->id)
                ->and($post->pillar?->id)->toBe($pillar->id)
                ->and($post->idea?->pillar_id)->toBe($pillar->id)
                ->and($run->fresh()?->post?->id)->toBe($post->id)
                ->and($pillar->posts()->count())->toBe(1);
        });
    });

    it('records the run as succeeded with tokens and the estimated cost in the same step', function () {
        $request = draftRequestIn($this->workspace, [$this->facebook]);
        $run = runningRun($this->workspace, $this->editor);

        inWorkspace($this->workspace, fn () => app(CreateDraftFromResult::class)->handle($this->editor, $run, draftResultFor($request, ['alt_text' => 'A cup.'])));

        expect(DB::table('draft_runs')->where('id', $run->id)->first())->toMatchObject([
            'status' => 'succeeded',
            'provider' => DRAFT_TEST_PROVIDER,
            'model' => DRAFT_TEST_MODEL,
            'prompt_version' => 'v1',
            'attempts' => 1,
            'input_tokens' => 1200,
            'output_tokens' => 300,
            'cost_estimate' => '0.005400',
            'cost_currency' => 'USD',
            'alt_text' => 'A cup.',
            'rationale' => 'Generated draft 1 fits the brief.',
        ]);
    });

    it('keeps a proposed time as the plan of the draft and schedules nothing', function () {
        $slot = slotIn($this->workspace, $this->facebook, weekday: 5, time: '09:00');
        $request = draftRequestIn($this->workspace, [$this->facebook]);
        $run = runningRun($this->workspace, $this->editor);

        $post = inWorkspace($this->workspace, fn () => app(CreateDraftFromResult::class)->handle(
            $this->editor, $run, draftResultFor($request), $slot->nextOccurrence(),
        ));

        // Friday 27 November 2026 at 09:00 in Johannesburg.
        expect($post->state)->toBe(PostState::Draft)
            ->and($post->scheduled_at?->toDateTimeString())->toBe('2026-11-27 07:00:00')
            ->and($post->approved_at)->toBeNull();
    });

    it('creates one post when the same result is delivered twice', function () {
        $request = draftRequestIn($this->workspace, [$this->facebook]);
        $run = runningRun($this->workspace, $this->editor);
        $result = draftResultFor($request);

        inWorkspace($this->workspace, function () use ($run, $result) {
            app(CreateDraftFromResult::class)->handle($this->editor, $run, $result);

            expect(fn () => app(CreateDraftFromResult::class)->handle($this->editor, $run, $result))->toThrow(DraftingStateConflict::class)
                ->and(Post::query()->count())->toBe(1);
        });
    });

    it('creates nothing for a run that is not running', function (string $status) {
        $request = draftRequestIn($this->workspace, [$this->facebook]);
        $run = draftRunIn($this->workspace, $this->editor);

        inWorkspace($this->workspace, function () use ($run, $request, $status) {
            if ($status === 'failed') {
                $run->markFailed('No.');
            }

            expect(fn () => app(CreateDraftFromResult::class)->handle($this->editor, $run, draftResultFor($request)))->toThrow(DraftingStateConflict::class)
                ->and(Post::query()->count())->toBe(0)
                ->and($run->fresh()?->status->value)->toBe($status);
        });
    })->with(['pending', 'failed']);

    it('leaves the run running and creates nothing when the actor may not write posts', function () {
        $request = draftRequestIn($this->workspace, [$this->facebook]);
        $run = runningRun($this->workspace, $this->editor);
        $viewer = userWithRole($this->workspace, Role::Viewer);

        inWorkspace($this->workspace, function () use ($run, $request, $viewer) {
            expect(fn () => app(CreateDraftFromResult::class)->handle($viewer, $run, draftResultFor($request)))->toThrow(PostActionNotAllowed::class)
                ->and(Post::query()->count())->toBe(0)
                ->and($run->fresh()?->status)->toBe(DraftRunStatus::Running)
                ->and($run->fresh()?->input_tokens)->toBeNull();
        });
    });

    it('cannot create a post in another workspace', function () {
        $other = Workspace::factory()->create();
        $request = draftRequestIn($this->workspace, [$this->facebook]);
        $run = runningRun($this->workspace, $this->editor);

        expect(fn () => inWorkspace($other, fn () => app(CreateDraftFromResult::class)->handle($this->editor, $run, draftResultFor($request))))->toThrow(Exception::class)
            ->and(DB::table('posts')->count())->toBe(0)
            ->and(DB::table('draft_runs')->where('id', $run->id)->value('status'))->toBe('running');
    });
});

describe('the origin of a post', function () {
    it('is empty on a post written by hand', function () {
        $post = postInState($this->workspace, PostState::Draft);

        expect([$post->draft_run_id, $post->pillar_id, $post->idea_id])->toBe([null, null, null]);
    });

    it('is recorded once, and only on a draft', function () {
        $run = draftRunIn($this->workspace, $this->editor);
        $again = draftRunIn($this->workspace, $this->editor);
        $draft = postInState($this->workspace, PostState::Draft);
        $approved = postInState($this->workspace, PostState::Approved);

        inWorkspace($this->workspace, function () use ($run, $again, $draft, $approved) {
            expect($draft->attributeTo($run)->draft_run_id)->toBe($run->id)
                ->and($draft->state)->toBe(PostState::Draft)
                ->and(fn () => $draft->attributeTo($again))->toThrow(PostNotEditable::class)
                ->and(fn () => $approved->attributeTo($run))->toThrow(PostNotEditable::class)
                ->and($approved->fresh()?->draft_run_id)->toBeNull();
        });
    });

    it('cannot point at a run, a pillar or an idea of another workspace', function () {
        $other = Workspace::factory()->create();
        $post = postInState($this->workspace, PostState::Draft);

        foreach ([
            'draft_run_id' => draftRunIn($other)->id,
            'pillar_id' => pillarIn($other)->id,
            'idea_id' => ideaIn($other)->id,
        ] as $column => $id) {
            expectRefusedByDatabase(fn () => DB::table('posts')->where('id', $post->id)->update([$column => $id]));
        }
    });

    it('is cleared, and the post kept, when the run, the pillar or the idea is deleted', function () {
        $pillar = pillarIn($this->workspace);
        $idea = ideaIn($this->workspace, $pillar);
        $request = draftRequestIn($this->workspace, [$this->facebook], $pillar, $idea);
        $run = runningRun($this->workspace, $this->editor, $pillar, $idea);

        $post = inWorkspace($this->workspace, fn () => app(CreateDraftFromResult::class)->handle($this->editor, $run, draftResultFor($request)));

        inWorkspace($this->workspace, function () use ($run, $pillar, $idea) {
            $idea->delete();
            $pillar->delete();
            $run->delete();
        });

        $row = DB::table('posts')->where('id', $post->id)->first();

        expect([$row->draft_run_id, $row->pillar_id, $row->idea_id])->toBe([null, null, null])
            ->and($row->workspace_id)->toBe($this->workspace->id)
            ->and($row->state)->toBe('draft');
    });
});

describe('GenerateDraft', function () {
    it('asks the generator of the workspace and creates the draft', function () {
        $request = draftRequestIn($this->workspace, [$this->facebook, $this->second]);
        $run = draftRunIn($this->workspace, $this->editor);

        $post = inWorkspace($this->workspace, fn () => app(GenerateDraft::class)->handle($this->editor, $run, $request));

        expect($post?->state)->toBe(PostState::Draft)
            ->and(variantsOf($post))->toHaveCount(2)
            ->and($run->status)->toBe(DraftRunStatus::Succeeded)
            ->and($run->cost_estimate)->toBe('0.005400')
            ->and($this->generator->lastRequest())->toBe($request)
            ->and($this->generator->lastContext()?->model)->toBe(DRAFT_TEST_MODEL)
            ->and($this->generator->lastContext()?->key->reveal())->toBe(DRAFT_TEST_KEY);

        $this->generator->assertGeneratedTimes(1);
    });

    it('uses the key the workspace brought', function () {
        inWorkspace($this->workspace, fn () => LlmCredential::store(DRAFT_TEST_PROVIDER, 'own-workspace-llm-key'));
        $request = draftRequestIn($this->workspace, [$this->facebook]);
        $run = draftRunIn($this->workspace, $this->editor);

        inWorkspace($this->workspace, fn () => app(GenerateDraft::class)->handle($this->editor, $run, $request));

        expect($this->generator->lastContext()?->key->reveal())->toBe('own-workspace-llm-key');
    });

    it('generates once when it is run twice for the same run', function () {
        $request = draftRequestIn($this->workspace, [$this->facebook]);
        $run = draftRunIn($this->workspace, $this->editor);

        inWorkspace($this->workspace, function () use ($run, $request) {
            $first = app(GenerateDraft::class)->handle($this->editor, $run, $request);
            $second = app(GenerateDraft::class)->handle($this->editor, DraftRun::findOrFail($run->id), $request);

            expect($first)->not->toBeNull()
                ->and($second)->toBeNull()
                ->and(Post::query()->count())->toBe(1);
        });

        $this->generator->assertGeneratedTimes(1);
    });

    it('fails the run visibly when the output is invalid twice, with what both attempts used', function () {
        $this->generator->failInvalid();
        $request = draftRequestIn($this->workspace, [$this->facebook]);
        $run = draftRunIn($this->workspace, $this->editor);

        inWorkspace($this->workspace, function () use ($run, $request) {
            expect(fn () => app(GenerateDraft::class)->handle($this->editor, $run, $request))->toThrow(InvalidDraftOutput::class)
                ->and(Post::query()->count())->toBe(0);
        });

        expect(DB::table('draft_runs')->where('id', $run->id)->first())->toMatchObject([
            'status' => 'failed',
            'failure_reason' => 'The generated draft could not be used: There is no text for channel 1.',
            'attempts' => 2,
            'input_tokens' => 2400,
            'output_tokens' => 600,
        ]);
    });

    it('fails the run when the generator returns output that does not validate', function () {
        $this->generator->returnsOutput(['variants' => [], 'link' => null, 'alt_text' => null, 'rationale' => 'None.']);
        $request = draftRequestIn($this->workspace, [$this->facebook]);
        $run = draftRunIn($this->workspace, $this->editor);

        inWorkspace($this->workspace, function () use ($run, $request) {
            expect(fn () => app(GenerateDraft::class)->handle($this->editor, $run, $request))->toThrow(InvalidDraftOutput::class);
        });

        expect($run->status)->toBe(DraftRunStatus::Failed)
            ->and($run->failure_reason)->toContain("There is no text for channel {$this->facebook->id}.")
            ->and($run->input_tokens)->toBe(1200)
            ->and($run->cost_estimate)->toBe('0.005400')
            ->and(DB::table('posts')->count())->toBe(0);
    });

    it('fails the run when the provider fails, and says whether trying again can help', function () {
        $this->generator->failRetryable(message: 'The provider is overloaded.');
        $request = draftRequestIn($this->workspace, [$this->facebook]);
        $run = draftRunIn($this->workspace, $this->editor);

        inWorkspace($this->workspace, function () use ($run, $request) {
            expect(fn () => app(GenerateDraft::class)->handle($this->editor, $run, $request))
                ->toThrow(fn (DraftGenerationFailed $failure) => expect($failure->retryable)->toBeTrue());
        });

        expect($run->status)->toBe(DraftRunStatus::Failed)
            ->and($run->failure_reason)->toBe('The provider is overloaded.')
            ->and($run->input_tokens)->toBeNull()
            ->and(DB::table('posts')->count())->toBe(0);
    });

    it('fails the run with a clear reason when there is no key', function () {
        config(['noongun.drafting.providers.'.DRAFT_TEST_PROVIDER.'.api_key' => null]);
        $request = draftRequestIn($this->workspace, [$this->facebook]);
        $run = draftRunIn($this->workspace, $this->editor);

        inWorkspace($this->workspace, function () use ($run, $request) {
            expect(fn () => app(GenerateDraft::class)->handle($this->editor, $run, $request))->toThrow(LlmCredentialMissing::class);
        });

        expect($run->status)->toBe(DraftRunStatus::Failed)
            ->and($run->failure_reason)->toContain('No API key is set for [fake]');

        $this->generator->assertNothingGenerated();
    });

    it('fails the run when the provider is not configured', function () {
        config(['noongun.drafting.provider' => 'nobody']);
        $request = draftRequestIn($this->workspace, [$this->facebook]);
        $run = draftRunIn($this->workspace, $this->editor);

        inWorkspace($this->workspace, function () use ($run, $request) {
            expect(fn () => app(GenerateDraft::class)->handle($this->editor, $run, $request))->toThrow(DraftGeneratorNotAvailable::class);
        });

        expect($run->status)->toBe(DraftRunStatus::Failed)
            ->and($run->failure_reason)->toContain('No draft generator is configured for provider [nobody]');
    });

    it('fails the run without leaking anything when something unexpected is thrown', function () {
        $this->generator->returnsOutput(fn () => throw new RuntimeException('cURL error with x-api-key: '.DRAFT_TEST_KEY));
        $request = draftRequestIn($this->workspace, [$this->facebook]);
        $run = draftRunIn($this->workspace, $this->editor);

        inWorkspace($this->workspace, function () use ($run, $request) {
            expect(fn () => app(GenerateDraft::class)->handle($this->editor, $run, $request))->toThrow(RuntimeException::class);
        });

        expect($run->status)->toBe(DraftRunStatus::Failed)
            ->and($run->failure_reason)->toBe('The draft could not be generated because of an unexpected error.')
            ->and(json_encode(DB::table('draft_runs')->where('id', $run->id)->first()))->not->toContain(DRAFT_TEST_KEY);
    });

    it('fails the run before any provider is called when the actor may not write posts', function () {
        $viewer = userWithRole($this->workspace, Role::Viewer);
        $request = draftRequestIn($this->workspace, [$this->facebook]);
        $run = draftRunIn($this->workspace, $viewer);

        inWorkspace($this->workspace, function () use ($run, $request, $viewer) {
            expect(fn () => app(GenerateDraft::class)->handle($viewer, $run, $request))->toThrow(DraftingNotAllowed::class);
        });

        expect($run->status)->toBe(DraftRunStatus::Failed)
            ->and($run->failure_reason)->toContain('may not')
            ->and(DB::table('posts')->count())->toBe(0);

        $this->generator->assertNothingGenerated();
    });
});
