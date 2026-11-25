<?php

use App\Domain\Channels\Contracts\ChannelCapabilities;
use App\Domain\Channels\Models\Channel;
use App\Domain\Content\Models\Post;
use App\Domain\Content\PostState;
use App\Domain\Workspaces\Models\Workspace;
use App\Domain\Workspaces\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../../Support/publishing.php';
require_once __DIR__.'/../../Support/posts.php';

beforeEach(function () {
    $this->travelTo('2026-11-24 10:00:00');
    Http::preventStrayRequests();

    $this->fake = fakeChannelAdapter();
    $this->workspace = Workspace::factory()->create(['timezone' => 'Europe/Copenhagen']);
    $this->owner = userWithRole($this->workspace, Role::Owner);
    $this->channel = channelIn($this->workspace, ['display_name' => 'Alpha']);
});

/**
 * What the editor sends for one channel.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function draftPayload(Channel $channel, array $overrides = []): array
{
    return [
        'scheduled_at' => null,
        'variants' => [
            ['channel_id' => $channel->id, 'body' => 'Fresh bread from eight.', 'link' => null],
        ],
        ...$overrides,
    ];
}

describe('new post page', function () {
    it('offers the channels that can publish, with their limits', function (Role $role) {
        $reconnect = channelIn($this->workspace, ['display_name' => 'Bravo', 'status' => 'needs_reconnect']);
        channelIn($this->workspace, ['display_name' => 'Gone', 'status' => 'disconnected', 'access_token' => null]);
        channelIn($this->workspace, ['display_name' => 'Unconfirmed', 'status' => 'pending']);

        $response = $this->actingAs(userWithRole($this->workspace, $role))->get(route('posts.create'));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('posts/Edit')
            ->where('post', null)
            ->where('planned', null)
            ->has('channels', 2)
            ->where('channels.0', [
                'id' => $this->channel->id,
                'display_name' => 'Alpha',
                'platform_label' => 'Facebook',
                'can_publish' => true,
                'problem' => null,
                'max_body_length' => 5000,
                'link_previews' => true,
            ])
            ->where('channels.1.id', $reconnect->id)
            ->where('channels.1.can_publish', false)
            ->where('channels.1.problem', 'Bravo must be connected again before it can publish.'));

        expect($response->getContent())->not->toContain('test-access-');
    })->with(['owner' => Role::Owner, 'editor' => Role::Editor]);

    it('starts from the day that was chosen in the calendar', function (string $date, ?string $planned) {
        $this->actingAs($this->owner)->get(route('posts.create', ['date' => $date]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('planned', $planned));
    })->with([
        'a day' => ['2026-11-26', '2026-11-26T09:00'],
        'not a date' => ['2026-13-45', null],
        'something else' => ['tomorrow', null],
    ]);

    it('is closed to viewers, guests and users without a workspace', function () {
        $this->actingAs(userWithRole($this->workspace, Role::Viewer))->get(route('posts.create'))->assertForbidden();
        $this->actingAs(User::factory()->create())->get(route('posts.create'))->assertForbidden();

        auth()->logout();

        $this->get(route('posts.create'))->assertRedirect(route('login'));
    });
});

describe('saving a new draft', function () {
    it('creates a draft with one variant per channel', function (Role $role) {
        $second = channelIn($this->workspace, ['display_name' => 'Bravo']);

        $response = $this->actingAs(userWithRole($this->workspace, $role))->post(route('posts.store'), [
            'scheduled_at' => null,
            'variants' => [
                ['channel_id' => $this->channel->id, 'body' => 'Fresh bread from eight.', 'link' => 'https://example.com/bread'],
                ['channel_id' => $second->id, 'body' => 'Bread. Eight.', 'link' => null],
            ],
        ]);

        $post = Post::query()->withoutGlobalScopes()->sole();

        $response->assertRedirect(route('posts.show', $post->id))
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'The draft is saved.']);

        [$first, $other] = variantsOf($post);

        expect($post->workspace_id)->toBe($this->workspace->id)
            ->and($post->state)->toBe(PostState::Draft)
            ->and($post->scheduled_at)->toBeNull()
            ->and($post->approved_at)->toBeNull()
            ->and($post->approved_by)->toBeNull()
            ->and($first->channel_id)->toBe($this->channel->id)
            ->and($first->body)->toBe('Fresh bread from eight.')
            ->and($first->link)->toBe('https://example.com/bread')
            ->and($first->workspace_id)->toBe($this->workspace->id)
            ->and($other->channel_id)->toBe($second->id)
            ->and($other->body)->toBe('Bread. Eight.')
            ->and($other->link)->toBeNull();

        $this->fake->assertNothingPublished();
    })->with(['owner' => Role::Owner, 'editor' => Role::Editor]);

    it('stores the planned time in UTC, read on the clock of the workspace', function (string $timezone, string $local, string $utc) {
        $this->workspace->update(['timezone' => $timezone]);

        $this->actingAs($this->owner)
            ->post(route('posts.store'), draftPayload($this->channel, ['scheduled_at' => $local]))
            ->assertRedirect();

        $post = Post::query()->withoutGlobalScopes()->sole();

        expect(DB::table('posts')->where('id', $post->id)->value('scheduled_at'))->toBe($utc)
            ->and($post->state)->toBe(PostState::Draft);

        $this->get(route('posts.show', $post->id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('post.scheduled_local', $local)
                ->where('post.scheduled_at', str_replace(' ', 'T', $utc).'+00:00'));
    })->with([
        'Copenhagen winter (UTC+1)' => ['Europe/Copenhagen', '2026-11-25T09:00', '2026-11-25 08:00:00'],
        'Copenhagen summer (UTC+2)' => ['Europe/Copenhagen', '2027-07-15T12:00', '2027-07-15 10:00:00'],
        'Copenhagen past midnight' => ['Europe/Copenhagen', '2026-11-26T00:30', '2026-11-25 23:30:00'],
        'Johannesburg (UTC+2, no DST)' => ['Africa/Johannesburg', '2026-11-25T09:00', '2026-11-25 07:00:00'],
        'Johannesburg in January' => ['Africa/Johannesburg', '2027-01-15T01:15', '2027-01-14 23:15:00'],
    ]);

    it('always creates a draft, whatever the request claims', function () {
        $this->actingAs($this->owner)->post(route('posts.store'), draftPayload($this->channel, [
            'state' => 'approved',
            'approved_at' => '2026-11-24 10:00:00',
            'approved_by' => $this->owner->id,
            'scheduled_at' => '2026-11-24T10:01',
        ]))->assertRedirect();

        $post = Post::query()->withoutGlobalScopes()->sole();

        expect($post->state)->toBe(PostState::Draft)
            ->and($post->approved_at)->toBeNull()
            ->and($post->approved_by)->toBeNull();

        $this->travel(5)->minutes();
        $this->artisan('noongun:dispatch-due-posts')->assertSuccessful();

        $this->fake->assertNothingPublished();
        expect(stateOf($post))->toBe('draft');
    });

    it('saves unfinished work: an empty text is allowed in a draft', function () {
        $this->actingAs($this->owner)
            ->post(route('posts.store'), draftPayload($this->channel, [
                'variants' => [['channel_id' => $this->channel->id, 'body' => '', 'link' => '']],
            ]))
            ->assertRedirect();

        $variant = variantsOf(Post::query()->withoutGlobalScopes()->sole())[0];

        expect($variant->body)->toBe('')
            ->and($variant->link)->toBeNull();
    });

    it('refuses what cannot be a draft', function (Closure $payload, string $field) {
        $this->actingAs($this->owner)
            ->post(route('posts.store'), $payload($this))
            ->assertSessionHasErrors($field);

        expect(DB::table('posts')->count())->toBe(0);
    })->with([
        'no channel' => [fn ($test) => ['variants' => []], 'variants'],
        'a channel of another workspace' => [
            fn ($test) => draftPayload(channelIn(Workspace::factory()->create())),
            'variants.0.channel_id',
        ],
        'the same channel twice' => [
            fn ($test) => ['variants' => [
                ['channel_id' => $test->channel->id, 'body' => 'One'],
                ['channel_id' => $test->channel->id, 'body' => 'Two'],
            ]],
            'variants.0.channel_id',
        ],
        'a link that is not a web address' => [
            fn ($test) => draftPayload($test->channel, ['variants' => [['channel_id' => $test->channel->id, 'body' => 'Hello', 'link' => 'javascript:alert(1)']]]),
            'variants.0.link',
        ],
        'a time that is not a time' => [
            fn ($test) => draftPayload($test->channel, ['scheduled_at' => 'next tuesday']),
            'scheduled_at',
        ],
    ]);

    it('only takes channels that can publish', function () {
        $expired = channelIn($this->workspace, ['display_name' => 'Old', 'token_expires_at' => now()->subDay()]);

        $this->actingAs($this->owner)
            ->post(route('posts.store'), draftPayload($expired))
            ->assertSessionHasErrors(['variants.0.channel_id' => 'The access to Old has expired. Connect it again.']);

        expect(DB::table('posts')->count())->toBe(0);
    });

    it('is refused for viewers', function () {
        $this->actingAs(userWithRole($this->workspace, Role::Viewer))
            ->post(route('posts.store'), draftPayload($this->channel))
            ->assertForbidden();

        expect(DB::table('posts')->count())->toBe(0);
    });

    it('sends guests to the login page', function () {
        $this->post(route('posts.store'), draftPayload($this->channel))->assertRedirect(route('login'));

        expect(DB::table('posts')->count())->toBe(0);
    });
});

describe('editor page', function () {
    it('shows a draft to every role, and lets owners and editors change it', function (Role $role, bool $mayChange) {
        $post = postInState($this->workspace, PostState::Draft, [$this->channel]);
        $variant = variantsOf($post)[0];

        $this->actingAs(userWithRole($this->workspace, $role))->get(route('posts.show', $post->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('posts/Edit')
                ->where('post.id', $post->id)
                ->where('post.state', 'draft')
                ->where('post.approval', null)
                ->where('post.version', postVersion($post))
                ->where('post.problems', [])
                ->where('post.variants.0.id', $variant->id)
                ->where('post.variants.0.channel_name', 'Alpha')
                ->where('post.variants.0.body', $variant->body)
                ->where('post.variants.0.max_body_length', 5000)
                ->where('post.variants.0.published', false)
                ->where('post.variants.0.permalink', null)
                ->where('post.can', [
                    'update' => $mayChange,
                    'approve' => $mayChange,
                    'reject' => $mayChange,
                    'schedule' => false,
                    'unschedule' => false,
                    'return_to_draft' => false,
                    'retry' => false,
                    'delete' => $mayChange,
                ]));
    })->with([
        'owner' => [Role::Owner, true],
        'editor' => [Role::Editor, true],
        'viewer' => [Role::Viewer, false],
    ]);

    it('says what stands in the way of approval before anyone presses approve', function () {
        $this->fake->withCapabilities(new ChannelCapabilities(maxBodyLength: 10));

        $empty = postInState($this->workspace, PostState::Draft, [$this->channel]);
        setBody($empty, '   ');
        $long = postInState($this->workspace, PostState::Draft, [$this->channel]);
        setBody($long, 'Longer than ten.');
        $late = postInState($this->workspace, PostState::Draft, [$this->channel], now()->subHour());
        setBody($late, 'Short.');
        $bare = inWorkspace($this->workspace, fn () => Post::factory()->create());

        $this->actingAs($this->owner);

        $this->get(route('posts.show', $empty->id))->assertInertia(fn (Assert $page) => $page
            ->where('post.problems', ['variants.'.variantsOf($empty)[0]->id.'.body' => ['The text for Alpha is empty.']]));

        $this->get(route('posts.show', $long->id))->assertInertia(fn (Assert $page) => $page
            ->where('post.variants.0.max_body_length', 10)
            ->where('post.problems', ['variants.'.variantsOf($long)[0]->id.'.body' => ['The text for Alpha is 16 characters long. The limit is 10.']]));

        $this->get(route('posts.show', $late->id))->assertInertia(fn (Assert $page) => $page
            ->where('post.problems', ['scheduled_at' => ['The scheduled time has passed. Choose a time in the future.']]));

        $this->get(route('posts.show', $bare->id))->assertInertia(fn (Assert $page) => $page
            ->where('post.problems', ['variants' => ['The post has no text for any channel. Add at least one channel.']]));
    });

    it('shows who approved a post and when, and what may be done with it', function (PostState $state, array $allowed) {
        $approver = User::factory()->create(['name' => 'Thandi Approver']);
        $post = inWorkspace($this->workspace, fn () => match ($state) {
            PostState::Approved => Post::factory()->withVariants([$this->channel])->approved($approver)->create(),
            PostState::Scheduled => Post::factory()->withVariants([$this->channel])->scheduled(now()->addDay(), $approver)->create(),
            PostState::Publishing => Post::factory()->withVariants([$this->channel])->publishing($approver)->create(),
            PostState::Published => Post::factory()->withVariants([$this->channel])->published($approver)->create(),
            PostState::Failed => Post::factory()->withVariants([$this->channel])->failed($approver)->create(),
        });

        $this->actingAs($this->owner)->get(route('posts.show', $post->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('post.state', $state->value)
                ->where('post.approval', ['at' => '2026-11-24T10:00:00+00:00', 'by' => 'Thandi Approver'])
                ->where('post.can', [
                    'update' => false,
                    'approve' => false,
                    'reject' => false,
                    'schedule' => in_array('schedule', $allowed, true),
                    'unschedule' => in_array('unschedule', $allowed, true),
                    'return_to_draft' => in_array('return_to_draft', $allowed, true),
                    'retry' => in_array('retry', $allowed, true),
                    'delete' => false,
                ]));
    })->with([
        'approved' => [PostState::Approved, ['schedule', 'return_to_draft']],
        'scheduled' => [PostState::Scheduled, ['schedule', 'unschedule', 'return_to_draft']],
        'publishing' => [PostState::Publishing, []],
        'published' => [PostState::Published, []],
        'failed' => [PostState::Failed, ['retry', 'return_to_draft']],
    ]);

    it('shows the permalink of a published post', function () {
        $post = postInState($this->workspace, PostState::Published, [$this->channel]);
        $variant = variantsOf($post)[0];

        $this->actingAs(userWithRole($this->workspace, Role::Viewer))->get(route('posts.show', $post->id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('post.state', 'published')
                ->where('post.variants.0.published', true)
                ->where('post.variants.0.permalink', 'https://platform.test/posts/ext_'.$variant->id)
                ->where('post.variants.0.published_at', '2026-11-24T10:00:00+00:00')
                ->where('post.variants.0.error', null));
    });

    it('shows the error of a failed post, and the same error on a post that is still being tried', function () {
        $failed = postInState($this->workspace, PostState::Failed, [$this->channel]);
        $publishing = postInState($this->workspace, PostState::Publishing, 1);
        DB::table('post_variants')->where('post_id', $publishing->id)->update(['error' => 'The platform is busy.']);

        $this->actingAs($this->owner);

        $this->get(route('posts.show', $failed->id))->assertInertia(fn (Assert $page) => $page
            ->where('post.state', 'failed')
            ->where('post.variants.0.error', 'The platform refused the post.')
            ->where('post.can.retry', true));

        // The page tells the two apart by the state of the post.
        $this->get(route('posts.show', $publishing->id))->assertInertia(fn (Assert $page) => $page
            ->where('post.state', 'publishing')
            ->where('post.variants.0.error', 'The platform is busy.')
            ->where('post.can.retry', false));
    });

    it('cannot be reached from another workspace', function () {
        $post = postInState($this->workspace, PostState::Draft, [$this->channel]);
        $outsider = userWithRole(Workspace::factory()->create(), Role::Owner);

        $this->actingAs($outsider)->get(route('posts.show', $post->id))->assertNotFound();
        $this->actingAs(User::factory()->create())->get(route('posts.show', $post->id))->assertNotFound();
    });

    it('answers 404 for a post that does not exist and sends guests to the login page', function () {
        $this->actingAs($this->owner)->get(route('posts.show', 999999))->assertNotFound();
        $this->get('/posts/not-a-number')->assertNotFound();

        auth()->logout();

        $this->get(route('posts.show', 1))->assertRedirect(route('login'));
    });
});

describe('saving changes to a draft', function () {
    it('replaces the texts, the channels and the planned time', function (Role $role) {
        $second = channelIn($this->workspace, ['display_name' => 'Bravo']);
        $third = channelIn($this->workspace, ['display_name' => 'Charlie']);
        $post = postInState($this->workspace, PostState::Draft, [$this->channel, $second]);
        [$kept] = variantsOf($post);

        $this->actingAs(userWithRole($this->workspace, $role))->put(route('posts.update', $post->id), [
            'scheduled_at' => '2026-11-25T09:00',
            'variants' => [
                ['channel_id' => $this->channel->id, 'body' => 'New text.', 'link' => 'https://example.com/new'],
                ['channel_id' => $third->id, 'body' => 'For Charlie.', 'link' => null],
            ],
        ])
            ->assertRedirect(route('posts.show', $post->id))
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'The draft is saved.']);

        $variants = variantsOf($post);

        expect($variants)->toHaveCount(2)
            ->and($variants[0]->id)->toBe($kept->id)
            ->and($variants[0]->body)->toBe('New text.')
            ->and($variants[0]->link)->toBe('https://example.com/new')
            ->and($variants[1]->channel_id)->toBe($third->id)
            ->and($variants[1]->body)->toBe('For Charlie.')
            ->and(DB::table('posts')->where('id', $post->id)->value('scheduled_at'))->toBe('2026-11-25 08:00:00')
            ->and(stateOf($post))->toBe('draft');
    })->with(['owner' => Role::Owner, 'editor' => Role::Editor]);

    it('clears the planned time when the field is empty', function () {
        $post = postInState($this->workspace, PostState::Draft, [$this->channel], now()->addDay());

        $this->actingAs($this->owner)
            ->put(route('posts.update', $post->id), draftPayload($this->channel, ['scheduled_at' => '']))
            ->assertRedirect(route('posts.show', $post->id));

        expect(freshPost($post)->scheduled_at)->toBeNull();
    });

    it('changes the fingerprint of the post, so an approval of the old text is refused', function () {
        $post = postInState($this->workspace, PostState::Draft, [$this->channel]);
        $before = postVersion($post);

        $this->actingAs($this->owner)
            ->put(route('posts.update', $post->id), draftPayload($this->channel))
            ->assertRedirect();

        expect(postVersion($post))->not->toBe($before);
    });

    it('is refused outside the draft state', function (PostState $state) {
        $post = postInState($this->workspace, $state, [$this->channel]);
        $before = variantsOf($post)[0]->body;
        $row = DB::table('posts')->where('id', $post->id)->first();

        $this->actingAs($this->owner)
            ->put(route('posts.update', $post->id), draftPayload($this->channel, ['scheduled_at' => '2026-12-01T09:00']))
            ->assertRedirect(route('posts.show', $post->id))
            ->assertInertiaFlash('toast', [
                'type' => 'error',
                'message' => 'The changes were not saved: the post is no longer a draft. Return it to draft to edit it.',
            ]);

        expect(variantsOf($post)[0]->body)->toBe($before)
            ->and(DB::table('posts')->where('id', $post->id)->first())->toEqual($row);
    })->with([
        PostState::Approved, PostState::Scheduled, PostState::Publishing,
        PostState::Published, PostState::Failed, PostState::Rejected,
    ]);

    it('never changes or removes a variant that is already published', function () {
        $second = channelIn($this->workspace, ['display_name' => 'Bravo']);
        $post = postInState($this->workspace, PostState::Failed, [$this->channel, $second]);
        [$published, $missing] = variantsOf($post);
        DB::table('post_variants')->where('id', $published->id)->update([
            'external_id' => 'ext_1', 'permalink' => 'https://platform.test/posts/ext_1', 'published_at' => now(), 'error' => null,
        ]);
        inWorkspace($this->workspace, fn () => $post->returnToDraft());

        // Leaves the published channel out and tries to rewrite it as well.
        $this->actingAs($this->owner)->put(route('posts.update', $post->id), [
            'scheduled_at' => null,
            'variants' => [['channel_id' => $second->id, 'body' => 'Second try.', 'link' => null]],
        ])->assertRedirect(route('posts.show', $post->id));

        $this->put(route('posts.update', $post->id), [
            'scheduled_at' => null,
            'variants' => [
                ['channel_id' => $this->channel->id, 'body' => 'Rewritten.', 'link' => null],
                ['channel_id' => $second->id, 'body' => 'Second try.', 'link' => null],
            ],
        ])->assertRedirect(route('posts.show', $post->id));

        [$stillPublished, $changed] = variantsOf($post);

        expect($stillPublished->id)->toBe($published->id)
            ->and($stillPublished->body)->toBe($published->body)
            ->and($stillPublished->external_id)->toBe('ext_1')
            ->and($changed->id)->toBe($missing->id)
            ->and($changed->body)->toBe('Second try.');
    });

    it('keeps a channel the post already uses when it can no longer publish, and takes no new one like it', function () {
        $post = postInState($this->workspace, PostState::Draft, [$this->channel]);
        $other = channelIn($this->workspace, ['display_name' => 'Bravo']);
        inWorkspace($this->workspace, function () use ($other) {
            $this->channel->markNeedsReconnect();
            $other->markNeedsReconnect();
        });

        $this->actingAs($this->owner);

        $this->put(route('posts.update', $post->id), draftPayload($this->channel, [
            'variants' => [['channel_id' => $this->channel->id, 'body' => 'Still editable.', 'link' => null]],
        ]))->assertSessionHasNoErrors();

        $this->put(route('posts.update', $post->id), [
            'variants' => [
                ['channel_id' => $this->channel->id, 'body' => 'Still editable.', 'link' => null],
                ['channel_id' => $other->id, 'body' => 'New.', 'link' => null],
            ],
        ])->assertSessionHasErrors(['variants.1.channel_id' => 'Bravo must be connected again before it can publish.']);

        expect(variantsOf($post))->toHaveCount(1)
            ->and(variantsOf($post)[0]->body)->toBe('Still editable.');
    });

    it('is refused for viewers', function () {
        $post = postInState($this->workspace, PostState::Draft, [$this->channel]);
        $before = variantsOf($post)[0]->body;

        $this->actingAs(userWithRole($this->workspace, Role::Viewer))
            ->put(route('posts.update', $post->id), draftPayload($this->channel))
            ->assertForbidden();

        expect(variantsOf($post)[0]->body)->toBe($before);
    });

    it('cannot reach a post of another workspace', function () {
        $post = postInState($this->workspace, PostState::Draft, [$this->channel]);
        $before = variantsOf($post)[0]->body;

        $elsewhere = Workspace::factory()->create();
        $outsider = userWithRole($elsewhere, Role::Owner);

        // With a channel of their own the request is valid, and finds nothing.
        $this->actingAs($outsider)
            ->put(route('posts.update', $post->id), draftPayload(channelIn($elsewhere)))
            ->assertNotFound();

        // With the channel of the post it is not even valid.
        $this->put(route('posts.update', $post->id), draftPayload($this->channel))
            ->assertSessionHasErrors('variants.0.channel_id');

        expect(variantsOf($post))->toHaveCount(1)
            ->and(variantsOf($post)[0]->body)->toBe($before);
    });
});

describe('deleting', function () {
    it('removes a draft or a rejected post with its variants', function (PostState $state, Role $role) {
        $post = postInState($this->workspace, $state, [$this->channel]);

        $this->actingAs(userWithRole($this->workspace, $role))
            ->delete(route('posts.destroy', $post->id))
            ->assertRedirect(route('queue.index'))
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'The post is deleted.']);

        expect(DB::table('posts')->where('id', $post->id)->exists())->toBeFalse()
            ->and(DB::table('post_variants')->where('post_id', $post->id)->exists())->toBeFalse()
            ->and(DB::table('channels')->where('id', $this->channel->id)->exists())->toBeTrue();

        $this->fake->assertNothingPublished();
        expect($this->fake->calls)->toBe([]);
    })->with([
        'a draft, by an owner' => [PostState::Draft, Role::Owner],
        'a draft, by an editor' => [PostState::Draft, Role::Editor],
        'a rejected post' => [PostState::Rejected, Role::Editor],
    ]);

    it('keeps everything that is approved or later', function (PostState $state) {
        $post = postInState($this->workspace, $state, [$this->channel]);

        $this->actingAs($this->owner)
            ->delete(route('posts.destroy', $post->id))
            ->assertRedirect(route('posts.show', $post->id))
            ->assertInertiaFlash('toast', [
                'type' => 'error',
                'message' => 'Only drafts and rejected posts that were never published can be deleted.',
            ]);

        expect(stateOf($post))->toBe($state->value)
            ->and(variantsOf($post))->toHaveCount(1);
    })->with([
        PostState::Approved, PostState::Scheduled, PostState::Publishing,
        PostState::Published, PostState::Failed,
    ]);

    it('keeps a draft that has a published variant', function () {
        $second = channelIn($this->workspace);
        $post = postInState($this->workspace, PostState::Failed, [$this->channel, $second]);
        DB::table('post_variants')->where('id', variantsOf($post)[0]->id)->update([
            'external_id' => 'ext_1', 'permalink' => 'https://platform.test/posts/ext_1', 'published_at' => now(), 'error' => null,
        ]);
        inWorkspace($this->workspace, fn () => $post->returnToDraft());

        $this->actingAs($this->owner);

        $this->get(route('posts.show', $post->id))->assertInertia(fn (Assert $page) => $page
            ->where('post.state', 'draft')
            ->where('post.can.delete', false)
            ->where('post.can.update', true));

        $this->delete(route('posts.destroy', $post->id))->assertRedirect(route('posts.show', $post->id));

        expect(stateOf($post))->toBe('draft')
            ->and(variantsOf($post))->toHaveCount(2);
    });

    it('is refused for viewers', function () {
        $post = postInState($this->workspace, PostState::Draft, [$this->channel]);

        $this->actingAs(userWithRole($this->workspace, Role::Viewer))
            ->delete(route('posts.destroy', $post->id))
            ->assertForbidden();

        expect(DB::table('posts')->where('id', $post->id)->exists())->toBeTrue();
    });

    it('cannot reach a post of another workspace', function () {
        $post = postInState($this->workspace, PostState::Draft, [$this->channel]);

        $this->actingAs(userWithRole(Workspace::factory()->create(), Role::Owner))
            ->delete(route('posts.destroy', $post->id))
            ->assertNotFound();

        expect(DB::table('posts')->where('id', $post->id)->exists())->toBeTrue();
    });

    it('sends guests to the login page', function () {
        $post = postInState($this->workspace, PostState::Draft, [$this->channel]);

        $this->delete(route('posts.destroy', $post->id))->assertRedirect(route('login'));

        expect(DB::table('posts')->where('id', $post->id)->exists())->toBeTrue();
    });
});

it('gives each role the post abilities it should have', function (Role $role, bool $mayChange) {
    $post = postInState($this->workspace, PostState::Draft, [$this->channel]);
    $user = userWithRole($this->workspace, $role);
    $outsider = userWithRole(Workspace::factory()->create(), Role::Owner);

    inWorkspace($this->workspace, function () use ($user, $outsider, $post, $mayChange) {
        expect($user->can('viewAny', Post::class))->toBeTrue()
            ->and($user->can('view', $post))->toBeTrue()
            ->and($user->can('create', Post::class))->toBe($mayChange)
            ->and($outsider->can('viewAny', Post::class))->toBeFalse()
            ->and($outsider->can('view', $post))->toBeFalse()
            ->and($outsider->can('create', Post::class))->toBeFalse();

        foreach (['update', 'delete', 'approve', 'reject', 'schedule', 'unschedule', 'returnToDraft', 'retry'] as $ability) {
            expect($user->can($ability, $post))->toBe($mayChange, $ability)
                ->and($outsider->can($ability, $post))->toBeFalse($ability);
        }
    });
})->with([
    'owner' => [Role::Owner, true],
    'editor' => [Role::Editor, true],
    'viewer' => [Role::Viewer, false],
]);
