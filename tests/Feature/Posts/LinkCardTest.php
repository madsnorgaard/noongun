<?php

/*
 * The card of a link: the title and description an author types for a
 * platform that reads nothing from the linked page.
 */

use App\Domain\Channels\Contracts\ChannelCapabilities;
use App\Domain\Content\Exceptions\PostNotEditable;
use App\Domain\Content\Exceptions\PostNotPublishable;
use App\Domain\Content\Models\Post;
use App\Domain\Content\Models\PostVariant;
use App\Domain\Content\PostPreconditions;
use App\Domain\Content\PostState;
use App\Domain\Workspaces\Models\Workspace;
use App\Domain\Workspaces\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../../Support/publishing.php';
require_once __DIR__.'/../../Support/posts.php';

beforeEach(function () {
    $this->travelTo('2026-11-25 10:00:00');
    Http::preventStrayRequests();

    $this->fake = fakeChannelAdapter()->withCapabilities(
        new ChannelCapabilities(maxBodyLength: 3000, linkTitleLength: 20, linkDescriptionLength: 40),
    );
    $this->workspace = Workspace::factory()->create(['timezone' => 'Africa/Johannesburg']);
    $this->owner = userWithRole($this->workspace, Role::Owner);
    $this->channel = channelIn($this->workspace, ['display_name' => 'Alpha']);

    $this->save = fn (array $variant) => $this->actingAs($this->owner)->post(route('posts.store'), [
        'scheduled_at' => null,
        'variants' => [['channel_id' => $this->channel->id, 'body' => 'Read this.', ...$variant]],
    ]);

    $this->draft = function (array $attributes): Post {
        $post = postInState($this->workspace, PostState::Draft, [$this->channel]);

        DB::table('post_variants')->where('post_id', $post->id)->update($attributes);

        return $post;
    };
});

it('stores the title and description with the link', function () {
    ($this->save)([
        'link' => 'https://example.com/bread',
        'link_title' => '  Bread at eight  ',
        'link_description' => 'Why we bake early.',
    ])->assertRedirect();

    [$variant] = variantsOf(Post::query()->withoutGlobalScopes()->sole());

    expect($variant->link)->toBe('https://example.com/bread')
        ->and($variant->link_title)->toBe('Bread at eight')
        ->and($variant->link_description)->toBe('Why we bake early.');

    Http::assertNothingSent();
});

it('does not keep a card without a link', function () {
    ($this->save)(['link' => '', 'link_title' => 'Bread at eight', 'link_description' => 'Why we bake early.'])->assertRedirect();

    [$variant] = variantsOf(Post::query()->withoutGlobalScopes()->sole());

    expect($variant->link_title)->toBeNull()
        ->and($variant->link_description)->toBeNull();
});

it('saves a draft without a card, so that unfinished work is kept', function () {
    ($this->save)(['link' => 'https://example.com/bread'])->assertRedirect()->assertSessionHasNoErrors();

    expect(variantsOf(Post::query()->withoutGlobalScopes()->sole())[0]->link_title)->toBeNull();
});

it('refuses a title that no column could hold', function () {
    ($this->save)(['link' => 'https://example.com/bread', 'link_title' => str_repeat('a', 401)])
        ->assertSessionHasErrors('variants.0.link_title');

    expect(DB::table('posts')->count())->toBe(0);
});

it('tells the editor which channels need a card, and shows the stored one', function () {
    $post = ($this->draft)(['link' => 'https://example.com/bread', 'link_title' => 'Bread at eight', 'link_description' => 'Early.']);

    $this->actingAs($this->owner)->get(route('posts.show', $post->id))->assertInertia(fn (Assert $page) => $page
        ->where('channels.0.link_title_length', 20)
        ->where('channels.0.link_description_length', 40)
        ->where('post.variants.0.link_title', 'Bread at eight')
        ->where('post.variants.0.link_description', 'Early.')
        ->where('post.problems', []));
});

it('asks for no card on a platform that builds its own preview', function () {
    $this->fake->withCapabilities(new ChannelCapabilities(maxBodyLength: 3000, linkPreviews: true));

    $post = ($this->draft)(['link' => 'https://example.com/bread']);

    $this->actingAs($this->owner)->get(route('posts.show', $post->id))->assertInertia(fn (Assert $page) => $page
        ->where('channels.0.link_title_length', null)
        ->where('post.problems', []));
});

it('holds a post back until its link has a card the platform accepts', function (array $attributes, string $key, string $message) {
    $post = ($this->draft)(['link' => 'https://example.com/bread', ...$attributes]);
    $variantId = variantsOf($post)[0]->id;

    $this->actingAs($this->owner)->get(route('posts.show', $post->id))->assertInertia(fn (Assert $page) => $page
        ->where('post.problems', ["variants.{$variantId}.{$key}" => [$message]]));

    expect(fn () => inWorkspace($this->workspace, fn () => app(PostPreconditions::class)->assertReadyToPublish($post)))
        ->toThrow(PostNotPublishable::class);
})->with([
    'no title' => [[], 'link_title', 'The link for Alpha has no title. Facebook shows a link with the title you give it and reads nothing from the page. Open the post and type a title under the link.'],
    'a title of spaces' => [['link_title' => '   '], 'link_title', 'The link for Alpha has no title. Facebook shows a link with the title you give it and reads nothing from the page. Open the post and type a title under the link.'],
    'a title that is too long' => [['link_title' => str_repeat('a', 21)], 'link_title', 'The link title for Alpha is 21 characters long. The limit is 20.'],
    'a description that is too long' => [['link_title' => 'Bread', 'link_description' => str_repeat('a', 41)], 'link_description', 'The link description for Alpha is 41 characters long. The limit is 40.'],
]);

it('needs no card when there is no link', function () {
    $post = ($this->draft)(['link' => null]);

    expect(inWorkspace($this->workspace, fn () => app(PostPreconditions::class)->problems($post)))->toBe([]);
});

it('changes the fingerprint of the post when the card changes, so an edited card is not approved unseen', function () {
    $post = ($this->draft)(['link' => 'https://example.com/bread', 'link_title' => 'Bread at eight']);
    $before = postVersion($post);

    DB::table('post_variants')->where('post_id', $post->id)->update(['link_title' => 'Bread at nine']);

    expect(postVersion($post))->not->toBe($before);
});

it('locks the card with the rest of the content once the post is approved', function () {
    $post = postInState($this->workspace, PostState::Approved, [$this->channel]);

    expect(fn () => inWorkspace($this->workspace, fn () => PostVariant::query()->where('post_id', $post->id)->sole()->update(['link_title' => 'Changed'])))
        ->toThrow(PostNotEditable::class);
});
