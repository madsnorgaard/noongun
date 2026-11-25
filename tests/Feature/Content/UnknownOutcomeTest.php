<?php

/*
 * A variant for which a platform was called without a recorded result may
 * be public. The row and its error text are the only trace of that, so they
 * cannot be removed, reset or deleted with their post.
 */

use App\Domain\Channels\Contracts\PublishResult;
use App\Domain\Channels\Models\Channel;
use App\Domain\Content\Actions\ApprovePost;
use App\Domain\Content\Actions\DeletePost;
use App\Domain\Content\Actions\ReturnPostToDraft;
use App\Domain\Content\Actions\SaveDraft;
use App\Domain\Content\Exceptions\PostNotDeletable;
use App\Domain\Content\Exceptions\PostNotEditable;
use App\Domain\Content\Exceptions\VariantAlreadyPublished;
use App\Domain\Content\Models\Post;
use App\Domain\Content\Models\PostVariant;
use App\Domain\Content\PostState;
use App\Domain\Workspaces\Models\Workspace;
use App\Domain\Workspaces\Role;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../../Support/publishing.php';

beforeEach(function () {
    $this->travelTo('2026-11-24 10:00:00');

    $this->fake = fakeChannelAdapter();
    $this->workspace = Workspace::factory()->create();
    $this->editor = userWithRole($this->workspace, Role::Editor);
});

/**
 * A draft that was approved, sent with an unknown outcome on its first
 * variant, failed, and returned to draft. Returns the post and its variants.
 *
 * @return array{Post, PostVariant, PostVariant}
 */
function draftWithUnknownOutcome(): array
{
    $channels = inWorkspace(test()->workspace, fn () => Channel::factory()->count(2)->create()->all());
    $post = postInState(test()->workspace, PostState::Failed, $channels);
    [$inDoubt, $plain] = variantsOf($post);

    DB::table('post_variants')->where('id', $inDoubt->id)->update([
        'outcome_unknown_since' => now()->subHour(),
        'error' => 'Publishing was interrupted before a result was recorded.',
    ]);

    inWorkspace(test()->workspace, fn () => app(ReturnPostToDraft::class)->handle(test()->editor, $post));

    return [$post, ...variantsOf($post)];
}

describe('PostVariant', function () {
    it('marks the attempt before the call and clears the mark on a refusal or a result', function () {
        [$post] = claimedPost($this->workspace, 2);

        inWorkspace($this->workspace, function () use ($post) {
            [$refused, $published] = $post->variants()->orderBy('id')->get()->all();

            expect($refused->hasUnknownOutcome())->toBeFalse()
                ->and($refused->beginAttempt())->toBeTrue()
                ->and($refused->hasUnknownOutcome())->toBeTrue()
                ->and($refused->outcome_unknown_since->toDateTimeString())->toBe('2026-11-24 10:00:00');

            $refused->markRefused('The text breaks the rules. access_token=EAAB-secret');

            expect($refused->hasUnknownOutcome())->toBeFalse()
                ->and($refused->error)->toContain('The text breaks the rules.')->not->toContain('EAAB-secret');

            $published->beginAttempt();
            $published->markPublished(new PublishResult('fb_1', null, now()));

            expect($published->outcome_unknown_since)->toBeNull()
                ->and($published->hasUnknownOutcome())->toBeFalse()
                ->and(fn () => $published->beginAttempt())->toThrow(VariantAlreadyPublished::class);
        });
    });

    it('keeps the first mark: a later attempt does not take it over, and a plain failure does not clear it', function () {
        [$post] = claimedPost($this->workspace, 1);

        inWorkspace($this->workspace, function () use ($post) {
            $variant = $post->variants()->firstOrFail();

            expect($variant->beginAttempt())->toBeTrue();

            $this->travel(1)->hours();

            expect($variant->beginAttempt())->toBeFalse()
                ->and($variant->outcome_unknown_since->toDateTimeString())->toBe('2026-11-24 10:00:00');

            $variant->markFailed('The channel is disconnected.');

            expect($variant->hasUnknownOutcome())->toBeTrue();
        });
    });

    it('does not let the mark be written directly', function () {
        $variant = inWorkspace($this->workspace, fn () => PostVariant::factory()->create());

        inWorkspace($this->workspace, function () use ($variant) {
            $variant->outcome_unknown_since = now();

            expect(fn () => $variant->save())->toThrow(PostNotEditable::class);
        });
    });

    it('cannot be deleted or moved to another channel while its outcome is unknown, but can be reworded', function () {
        [, $inDoubt] = draftWithUnknownOutcome();
        $elsewhere = inWorkspace($this->workspace, fn () => Channel::factory()->create());

        inWorkspace($this->workspace, function () use ($inDoubt, $elsewhere) {
            expect(fn () => $inDoubt->delete())->toThrow(PostNotEditable::class, 'may already be on its platform');

            $inDoubt->channel_id = $elsewhere->id;
            expect(fn () => $inDoubt->save())->toThrow(PostNotEditable::class, 'may already be on its platform');

            $inDoubt->refresh()->update(['body' => 'A corrected text.']);

            expect($inDoubt->refresh()->body)->toBe('A corrected text.')
                ->and($inDoubt->hasUnknownOutcome())->toBeTrue()
                ->and($inDoubt->error)->toBe('Publishing was interrupted before a result was recorded.');
        });
    });
});

describe('SaveDraft', function () {
    it('keeps a variant with an unknown outcome when its channel is left out, with its error text', function () {
        [$post, $inDoubt, $plain] = draftWithUnknownOutcome();

        inWorkspace($this->workspace, fn () => app(SaveDraft::class)->handle($this->editor, $post, null, []));

        $left = variantsOf($post);

        expect($left)->toHaveCount(1)
            ->and($left[0]->id)->toBe($inDoubt->id)
            ->and($left[0]->hasUnknownOutcome())->toBeTrue()
            ->and($left[0]->error)->toBe('Publishing was interrupted before a result was recorded.');
    });

    it('does not hand out a fresh variant for the channel: leaving it out and adding it again changes nothing', function () {
        [$post, $inDoubt] = draftWithUnknownOutcome();

        inWorkspace($this->workspace, function () use ($post, $inDoubt) {
            app(SaveDraft::class)->handle($this->editor, $post, null, []);
            app(SaveDraft::class)->handle($this->editor, $post, null, [
                ['channel_id' => $inDoubt->channel_id, 'body' => 'Written again.', 'link' => null],
            ]);
        });

        $left = variantsOf($post);

        expect($left)->toHaveCount(1)
            ->and($left[0]->id)->toBe($inDoubt->id)
            ->and($left[0]->body)->toBe('Written again.')
            ->and($left[0]->hasUnknownOutcome())->toBeTrue()
            ->and($left[0]->error)->toBe('Publishing was interrupted before a result was recorded.');
    });

    it('still carries the warning after the post is approved again', function () {
        [$post, $inDoubt] = draftWithUnknownOutcome();

        inWorkspace($this->workspace, function () use ($post) {
            app(SaveDraft::class)->handle($this->editor, $post, null, []);
            app(ApprovePost::class)->handle($this->editor, $post);
        });

        expect(freshPost($post)->state)->toBe(PostState::Approved)
            ->and(variantsOf($post)[0]->id)->toBe($inDoubt->id)
            ->and(variantsOf($post)[0]->hasUnknownOutcome())->toBeTrue();
    });

    it('still removes an ordinary variant that is left out', function () {
        $post = postInState($this->workspace, PostState::Draft, 2);

        inWorkspace($this->workspace, fn () => app(SaveDraft::class)->handle($this->editor, $post, null, []));

        expect(variantsOf($post))->toBe([]);
    });
});

describe('DeletePost', function () {
    it('keeps a draft that has a variant with an unknown outcome', function () {
        [$post] = draftWithUnknownOutcome();

        expect(inWorkspace($this->workspace, fn () => DeletePost::allows(Post::findOrFail($post->id))))->toBeFalse()
            ->and(fn () => inWorkspace($this->workspace, fn () => app(DeletePost::class)->handle($this->editor, $post)))
            ->toThrow(PostNotDeletable::class, 'may be on its platform');

        expect(DB::table('posts')->where('id', $post->id)->exists())->toBeTrue()
            ->and(variantsOf($post))->toHaveCount(2);
    });

    it('keeps a rejected post with such a variant as well', function () {
        $post = postInState($this->workspace, PostState::Rejected, 1);
        DB::table('post_variants')->where('post_id', $post->id)->update(['outcome_unknown_since' => now()]);

        expect(fn () => inWorkspace($this->workspace, fn () => app(DeletePost::class)->handle($this->editor, $post)))
            ->toThrow(PostNotDeletable::class);
    });

    it('still deletes a draft whose only failures were refusals', function () {
        $post = postInState($this->workspace, PostState::Failed, 2);
        inWorkspace($this->workspace, function () use ($post) {
            app(ReturnPostToDraft::class)->handle($this->editor, $post);
            app(DeletePost::class)->handle($this->editor, $post);
        });

        expect(DB::table('posts')->where('id', $post->id)->exists())->toBeFalse();
    });
});
