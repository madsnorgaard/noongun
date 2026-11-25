<?php

use App\Domain\Channels\Contracts\PublishResult;
use App\Domain\Channels\Models\Channel;
use App\Domain\Content\Exceptions\PostNotEditable;
use App\Domain\Content\Exceptions\VariantAlreadyPublished;
use App\Domain\Content\Models\Post;
use App\Domain\Content\Models\PostVariant;
use App\Domain\Content\PostState;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

itIsolatesWorkspaces(fn () => PostVariant::factory()->create());

beforeEach(function () {
    $this->workspace = Workspace::factory()->create();
});

it('belongs to a post and a channel', function () {
    $variant = inWorkspace($this->workspace, fn () => PostVariant::factory()->withLink('https://example.com/a')->create(['body' => 'Hello']));

    inWorkspace($this->workspace, function () use ($variant) {
        $variant = PostVariant::findOrFail($variant->id);

        expect($variant->post)->toBeInstanceOf(Post::class)
            ->and($variant->channel)->toBeInstanceOf(Channel::class)
            ->and($variant->post->variants->modelKeys())->toBe([$variant->id])
            ->and($variant->channel->variants->modelKeys())->toBe([$variant->id])
            ->and($variant->body)->toBe('Hello')
            ->and($variant->link)->toBe('https://example.com/a')
            ->and($variant->utm)->toBe(['utm_source' => 'facebook', 'utm_medium' => 'social'])
            ->and($variant->isPublished())->toBeFalse()
            ->and($variant->hasFailed())->toBeFalse();
    });
});

it('allows one variant per post and channel', function () {
    inWorkspace($this->workspace, function () {
        $variant = PostVariant::factory()->create();

        expect(fn () => PostVariant::factory()->create(['post_id' => $variant->post_id, 'channel_id' => $variant->channel_id]))
            ->toThrow(QueryException::class);
    });
});

it('cannot point at a post or channel of another workspace', function () {
    $other = Workspace::factory()->create();
    $foreignChannel = inWorkspace($other, fn () => Channel::factory()->create());
    $foreignPost = inWorkspace($other, fn () => Post::factory()->create());

    inWorkspace($this->workspace, function () use ($foreignChannel, $foreignPost) {
        // In a nested transaction, so the failed insert does not abort the test's own.
        expect(fn () => DB::transaction(fn () => PostVariant::factory()->create(['channel_id' => $foreignChannel->id])))->toThrow(QueryException::class)
            ->and(fn () => PostVariant::factory()->create(['post_id' => $foreignPost->id]))->toThrow(PostNotEditable::class);
    });

    $variant = inWorkspace($this->workspace, fn () => PostVariant::factory()->create());

    expect(fn () => DB::table('post_variants')->where('id', $variant->id)->update(['post_id' => $foreignPost->id]))
        ->toThrow(QueryException::class);
});

it('is deleted with its post', function () {
    $variant = inWorkspace($this->workspace, fn () => PostVariant::factory()->create());

    inWorkspace($this->workspace, fn () => $variant->post->delete());

    expect(DB::table('post_variants')->count())->toBe(0);
});

describe('what was approved is what gets published', function () {
    it('can be changed while its post is a draft', function () {
        $variant = inWorkspace($this->workspace, fn () => PostVariant::factory()->create());

        inWorkspace($this->workspace, fn () => $variant->update(['body' => 'A better text']));

        expect(DB::table('post_variants')->where('id', $variant->id)->value('body'))->toBe('A better text');
    });

    it('cannot be changed, added or deleted once its post has left draft', function (PostState $state) {
        $post = inWorkspace($this->workspace, fn () => match ($state) {
            PostState::Approved => Post::factory()->withVariants(1)->approved()->create(),
            PostState::Scheduled => Post::factory()->withVariants(1)->scheduled()->create(),
            PostState::Publishing => Post::factory()->withVariants(1)->publishing()->create(),
            PostState::Failed => Post::factory()->withVariants(1)->failed()->create(),
            PostState::Rejected => Post::factory()->withVariants(1)->rejected()->create(),
        });

        inWorkspace($this->workspace, function () use ($post) {
            $variant = $post->variants->first();
            $body = $variant->body;

            foreach ([
                fn () => $variant->update(['body' => 'Not what was approved']),
                fn () => $variant->update(['link' => 'https://example.com/other']),
                fn () => $variant->update(['utm' => ['utm_source' => 'x']]),
                fn () => $variant->update(['channel_id' => Channel::factory()->create()->id]),
                fn () => $variant->delete(),
                fn () => PostVariant::factory()->create(['post_id' => $post->id]),
                fn () => $post->variants()->create(['channel_id' => Channel::factory()->create()->id, 'body' => 'Sneaked in']),
            ] as $attempt) {
                expect($attempt)->toThrow(PostNotEditable::class);
                $variant->refresh();
            }

            expect($variant->body)->toBe($body)
                ->and($post->variants()->count())->toBe(1);
        });
    })->with([PostState::Approved, PostState::Scheduled, PostState::Publishing, PostState::Failed, PostState::Rejected]);

    it('can be changed again after its post returns to draft, which erases the approval', function () {
        $post = inWorkspace($this->workspace, fn () => Post::factory()->withVariants(1)->scheduled()->create());

        inWorkspace($this->workspace, function () use ($post) {
            $post->returnToDraft();
            $post->variants->first()->update(['body' => 'Rewritten']);
        });

        expect($post->state)->toBe(PostState::Draft)
            ->and($post->approved_at)->toBeNull();
    });

    it('can never be changed once it is published, even on a draft', function () {
        $post = inWorkspace($this->workspace, fn () => Post::factory()->withVariants(2)->failed()->create());

        inWorkspace($this->workspace, function () use ($post) {
            [$published, $failed] = $post->variants->all();
            $published->markPublished(new PublishResult('1_2', null, now()));
            $post->returnToDraft();

            expect(fn () => $published->update(['body' => 'Changed after the fact']))->toThrow(PostNotEditable::class)
                ->and(fn () => $published->delete())->toThrow(PostNotEditable::class);

            $failed->update(['body' => 'Shorter']);

            expect($failed->refresh()->body)->toBe('Shorter');
        });
    });

    it('cannot be moved to another post', function () {
        inWorkspace($this->workspace, function () {
            $variant = PostVariant::factory()->create();

            expect(fn () => $variant->update(['post_id' => Post::factory()->create()->id]))->toThrow(PostNotEditable::class);
        });
    });
});

describe('publishing outcome', function () {
    it('is not mass assignable and cannot be saved directly', function () {
        inWorkspace($this->workspace, function () {
            $variant = PostVariant::factory()->create();
            $variant->update(['external_id' => '1_2', 'permalink' => 'https://x.test', 'published_at' => now(), 'error' => 'no']);

            expect($variant->refresh()->external_id)->toBeNull()
                ->and($variant->error)->toBeNull()
                ->and(fn () => $variant->forceFill(['external_id' => '1_2', 'published_at' => now()])->save())->toThrow(PostNotEditable::class)
                ->and(fn () => $variant->refresh()->forceFill(['error' => 'no'])->save())->toThrow(PostNotEditable::class);
        });
    });

    it('records a publish result in UTC and clears an earlier error', function (string $timezone) {
        $post = inWorkspace($this->workspace, fn () => Post::factory()->withVariants(1)->publishing()->create());

        $variant = inWorkspace($this->workspace, function () use ($post, $timezone) {
            $variant = $post->variants->first()->markFailed('The platform is busy.');

            expect($variant->hasFailed())->toBeTrue();

            return $variant->markPublished(new PublishResult(
                '123_456',
                'https://www.facebook.com/123_456',
                new DateTimeImmutable('2026-07-15 12:00:00', new DateTimeZone($timezone)),
            ));
        });

        expect($variant->isPublished())->toBeTrue()
            ->and($variant->hasFailed())->toBeFalse()
            ->and($variant->external_id)->toBe('123_456')
            ->and($variant->permalink)->toBe('https://www.facebook.com/123_456')
            ->and($variant->error)->toBeNull()
            ->and(DB::table('post_variants')->where('id', $variant->id)->value('published_at'))->toBe('2026-07-15 10:00:00');
    })->with(['Europe/Copenhagen', 'Africa/Johannesburg']);

    it('keeps the first result when a second one arrives', function () {
        $post = inWorkspace($this->workspace, fn () => Post::factory()->withVariants(1)->publishing()->create());

        inWorkspace($this->workspace, function () use ($post) {
            $variant = $post->variants->first();
            $sameRowElsewhere = PostVariant::findOrFail($variant->id);

            $variant->markPublished(new PublishResult('first', null, now()));

            expect(fn () => $sameRowElsewhere->markPublished(new PublishResult('second', null, now())))
                ->toThrow(VariantAlreadyPublished::class)
                ->and($sameRowElsewhere->external_id)->toBe('first');

            $sameRowElsewhere->markFailed('A late failure.');

            expect($sameRowElsewhere->error)->toBeNull()
                ->and($sameRowElsewhere->isPublished())->toBeTrue();
        });
    });

    it('never stores a token in the error', function () {
        $variant = inWorkspace($this->workspace, fn () => PostVariant::factory()->create()
            ->markFailed('cURL error 28 for https://graph.facebook.com/v27.0/1/feed?access_token=EAAB-s3cret'));

        expect($variant->error)->not->toContain('EAAB-s3cret')
            ->and($variant->error)->toContain('cURL error 28');
    });

    it('records one platform post on one variant only', function () {
        inWorkspace($this->workspace, function () {
            $channel = Channel::factory()->create();
            $first = PostVariant::factory()->for($channel)->create();
            $second = PostVariant::factory()->for($channel)->create();

            $first->markPublished(new PublishResult('1_2', null, now()));

            expect(fn () => $second->markPublished(new PublishResult('1_2', null, now())))->toThrow(QueryException::class);
        });
    });

    it('lists unpublished variants', function () {
        $post = inWorkspace($this->workspace, fn () => Post::factory()->withVariants(2)->publishing()->create());

        inWorkspace($this->workspace, function () use ($post) {
            $post->variants->first()->markPublished(new PublishResult('1_2', null, now()));

            expect($post->variants()->unpublished()->pluck('id')->all())->toBe([$post->variants->last()->id]);
        });
    });
});

describe('factory', function () {
    it('makes published and failed variants', function () {
        inWorkspace($this->workspace, function () {
            expect(PostVariant::factory()->published()->create()->isPublished())->toBeTrue()
                ->and(PostVariant::factory()->failed()->create()->hasFailed())->toBeTrue();
        });
    });

    it('makes posts in every state with variants on given channels', function () {
        inWorkspace($this->workspace, function () {
            $channels = Channel::factory()->count(2)->create();
            $published = Post::factory()->withVariants($channels)->published()->create();
            $failed = Post::factory()->withVariants(1)->failed()->create();

            expect($published->state)->toBe(PostState::Published)
                ->and($published->variants->pluck('channel_id')->all())->toBe($channels->modelKeys())
                ->and($published->variants->every->isPublished())->toBeTrue()
                ->and($failed->variants->every->hasFailed())->toBeTrue()
                ->and(Post::factory()->due()->create()->scheduled_at->isPast())->toBeTrue();
        });
    });
});
