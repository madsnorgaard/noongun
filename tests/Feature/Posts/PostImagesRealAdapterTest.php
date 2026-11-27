<?php

use App\Domain\Content\Models\Post;
use App\Domain\Content\PostState;
use App\Domain\Workspaces\Models\Workspace;
use App\Domain\Workspaces\Role;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../../Support/publishing.php';
require_once __DIR__.'/../../Support/posts.php';
require_once __DIR__.'/../../Support/media.php';

/*
 * The editor against the adapters as they are, with no fake in their
 * place: what the pages are told is what the platform code declares.
 */

beforeEach(function () {
    $this->travelTo('2026-11-24 10:00:00');

    Http::preventStrayRequests();
    fakeMediaDisk();
    $this->workspace = Workspace::factory()->create(['timezone' => 'Europe/Copenhagen']);
    $this->owner = userWithRole($this->workspace, Role::Owner);
    $this->page = channelIn($this->workspace, ['display_name' => 'Bakery page', 'platform' => 'facebook']);
});

it('offers images on a Facebook Page with the limits its adapter declares', function () {
    $this->actingAs($this->owner)->get(route('posts.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('channels.0.display_name', 'Bakery page')
            ->where('channels.0.media.max_items', fn (int $max) => $max > 0 && $max <= config('noongun.media.max_per_variant'))
            ->where('channels.0.media.types', ['image/jpeg', 'image/png'])
            ->where('channels.0.media.max_bytes', fn ($bytes) => is_int($bytes) && $bytes > 0)
            ->where('channels.0.media.alt_text_length', fn ($length) => is_int($length) && $length > 0)
            ->where('channels.0.media.link_rule', 'in_text'));
});

it('saves images for a Facebook Page, advises on a missing description and approves the post', function () {
    $harbour = storedImage($this->workspace);
    $gull = storedImage($this->workspace, pngBytes());

    $this->actingAs($this->owner)->post(route('posts.store'), [
        'scheduled_at' => null,
        'variants' => [[
            'channel_id' => $this->page->id,
            'body' => 'Fresh bread from eight.',
            'link' => 'https://example.com/bread',
            'media' => [['id' => $gull->id, 'alt_text' => 'A gull.'], ['id' => $harbour->id, 'alt_text' => '']],
        ]],
    ])->assertRedirect();

    $post = Post::query()->withoutGlobalScopes()->sole();
    $variant = variantsOf($post)[0];

    expect(imagesOf($variant))->toBe([[$gull->id, 'A gull.'], [$harbour->id, null]]);

    $this->get(route('posts.show', $post->id))
        ->assertInertia(fn (Assert $page) => $page
            ->where('post.problems', [])
            ->where('post.advice', fn ($advice) => collect($advice)->keys()->sort()->values()->all() === collect([
                "variants.{$variant->id}.link",
                "variants.{$variant->id}.media.{$harbour->id}.alt_text",
            ])->sort()->values()->all())
            ->where('post.variants.0.media.0.id', $gull->id)
            ->where('post.variants.0.link_as_sent', 'https://example.com/bread'));

    $this->post(route('posts.approve', $post->id), ['version' => postVersion($post)])->assertSessionHasNoErrors();

    expect(stateOf($post))->toBe(PostState::Approved->value);
});
