<?php

use App\Domain\Content\PostState;
use App\Domain\Workspaces\Models\Workspace;
use App\Domain\Workspaces\Role;
use App\Http\Requests\Brief\SaveIdeaRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../../Support/members.php';
require_once __DIR__.'/../../Support/publishing.php';

beforeEach(function () {
    $this->travelTo('2026-11-25 10:00:00');

    $this->workspace = Workspace::factory()->create(['timezone' => 'Africa/Johannesburg']);
    $this->owner = workspaceMember($this->workspace, Role::Owner);
    $this->pillar = pillarIn($this->workspace, ['name' => 'Roast']);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function ideaPayload(array $overrides = []): array
{
    return [
        'title' => 'Why we roast on Tuesdays',
        'pillar_id' => null,
        'source_url' => 'https://example.com/roasting?week=48',
        'notes' => "Mention the new drum.\nKeep it short.",
        ...$overrides,
    ];
}

describe('list', function () {
    it('shows the ideas, newest first, with their pillar', function (Role $role, bool $mayManage) {
        $first = ideaIn($this->workspace, $this->pillar, ['title' => 'First', 'source_url' => 'https://example.com/a', 'notes' => 'A note.']);
        $second = ideaIn($this->workspace, null, ['title' => 'Second']);

        $this->actingAs(workspaceMember($this->workspace, $role))->get(route('ideas.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ideas/Index')
                ->has('ideas', 2)
                ->where('ideas.0.id', $second->id)
                ->where('ideas.0.pillar_id', null)
                ->where('ideas.0.pillar_name', null)
                ->where('ideas.1', [
                    'id' => $first->id,
                    'title' => 'First',
                    'pillar_id' => $this->pillar->id,
                    'pillar_name' => 'Roast',
                    'source_url' => 'https://example.com/a',
                    'notes' => 'A note.',
                    'posts_count' => 0,
                    'created_at' => '2026-11-25T10:00:00+00:00',
                ])
                ->where('pillars', [['id' => $this->pillar->id, 'name' => 'Roast']])
                ->where('limits.source_url', 2048)
                ->where('workspace.timezone', 'Africa/Johannesburg')
                ->where('can.manage', $mayManage));
    })->with([
        'owner' => [Role::Owner, true],
        'editor' => [Role::Editor, true],
        'viewer' => [Role::Viewer, false],
    ]);

    it('is not shown to someone without a workspace, and guests are sent to the login page', function () {
        $this->actingAs(User::factory()->create())->get(route('ideas.index'))->assertForbidden();

        auth()->logout();

        $this->get(route('ideas.index'))->assertRedirect(route('login'));
    });
});

describe('creating', function () {
    it('adds an idea with an optional pillar, source and notes', function (Role $role) {
        $this->actingAs(workspaceMember($this->workspace, $role))
            ->post(route('ideas.store'), ideaPayload(['pillar_id' => $this->pillar->id]))
            ->assertRedirect(route('ideas.index'))
            ->assertSessionHasNoErrors();

        $this->post(route('ideas.store'), ['title' => 'Only a title'])->assertSessionHasNoErrors();

        $rows = DB::table('ideas')->orderBy('id')->get();

        expect($rows)->toHaveCount(2)
            ->and($rows[0]->workspace_id)->toBe($this->workspace->id)
            ->and($rows[0]->title)->toBe('Why we roast on Tuesdays')
            ->and($rows[0]->pillar_id)->toBe($this->pillar->id)
            ->and($rows[0]->source_url)->toBe('https://example.com/roasting?week=48')
            ->and($rows[0]->notes)->toBe("Mention the new drum.\nKeep it short.")
            ->and($rows[1]->pillar_id)->toBeNull()
            ->and($rows[1]->source_url)->toBeNull()
            ->and($rows[1]->notes)->toBeNull();
    })->with(['owner' => Role::Owner, 'editor' => Role::Editor]);

    it('stores the source as text and never opens it', function (string $url) {
        Http::fake();

        $this->actingAs($this->owner)
            ->post(route('ideas.store'), ideaPayload(['source_url' => $url]))
            ->assertSessionHasNoErrors();

        $this->get(route('ideas.index'))->assertOk();

        expect(DB::table('ideas')->sole()->source_url)->toBe($url);

        Http::assertNothingSent();
    })->with([
        'a public address' => ['https://example.com/article'],
        'an address on the local network' => ['http://192.168.1.1/admin'],
        'the metadata address of a cloud host' => ['http://169.254.169.254/latest/meta-data/'],
        'this machine' => ['http://localhost:8082/up'],
    ]);

    it('refuses what is missing, too long or not a web address', function (array $payload, string $field) {
        $this->actingAs($this->owner)
            ->post(route('ideas.store'), ideaPayload($payload))
            ->assertSessionHasErrors($field);

        expect(DB::table('ideas')->count())->toBe(0);
    })->with([
        'no title' => [['title' => ''], 'title'],
        'a long title' => [['title' => str_repeat('a', SaveIdeaRequest::MAX_TITLE_LENGTH + 1)], 'title'],
        'a script address' => [['source_url' => 'javascript:alert(1)'], 'source_url'],
        'a file address' => [['source_url' => 'file:///etc/passwd'], 'source_url'],
        'an ftp address' => [['source_url' => 'ftp://example.com/a'], 'source_url'],
        'an address without a scheme' => [['source_url' => 'example.com/article'], 'source_url'],
        'an address of 2049 characters' => [['source_url' => 'https://example.com/'.str_repeat('a', 2049 - 20)], 'source_url'],
        'long notes' => [['notes' => str_repeat('a', SaveIdeaRequest::MAX_NOTES_LENGTH + 1)], 'notes'],
        'a pillar that does not exist' => [['pillar_id' => 999999], 'pillar_id'],
        'a pillar that is not a number' => [['pillar_id' => 'roast'], 'pillar_id'],
    ]);

    it('accepts an address of exactly 2048 characters', function () {
        $url = 'https://example.com/'.str_repeat('a', 2048 - 20);

        $this->actingAs($this->owner)
            ->post(route('ideas.store'), ideaPayload(['source_url' => $url]))
            ->assertSessionHasNoErrors();

        expect(strlen(DB::table('ideas')->sole()->source_url))->toBe(2048);
    });

    it('is refused for viewers', function () {
        $this->actingAs(workspaceMember($this->workspace, Role::Viewer))
            ->post(route('ideas.store'), ideaPayload())
            ->assertForbidden();

        expect(DB::table('ideas')->count())->toBe(0);
    });
});

describe('changing', function () {
    beforeEach(function () {
        $this->idea = ideaIn($this->workspace, $this->pillar, ['title' => 'Before', 'source_url' => 'https://example.com/a', 'notes' => 'Old.']);
    });

    it('replaces the title, the pillar, the source and the notes', function (Role $role) {
        $this->actingAs(workspaceMember($this->workspace, $role))
            ->put(route('ideas.update', $this->idea->id), ['title' => 'After', 'pillar_id' => null, 'source_url' => '', 'notes' => ''])
            ->assertRedirect(route('ideas.index'))
            ->assertSessionHasNoErrors();

        $row = DB::table('ideas')->sole();

        expect($row->title)->toBe('After')
            ->and($row->pillar_id)->toBeNull()
            ->and($row->source_url)->toBeNull()
            ->and($row->notes)->toBeNull();
    })->with(['owner' => Role::Owner, 'editor' => Role::Editor]);

    it('validates as on creation', function () {
        $this->actingAs($this->owner)
            ->put(route('ideas.update', $this->idea->id), ideaPayload(['source_url' => 'javascript:alert(1)']))
            ->assertSessionHasErrors('source_url');

        expect(DB::table('ideas')->sole()->title)->toBe('Before');
    });

    it('is refused for viewers and answers 404 for an idea that does not exist', function () {
        $this->actingAs(workspaceMember($this->workspace, Role::Viewer))
            ->put(route('ideas.update', $this->idea->id), ideaPayload())
            ->assertForbidden();

        $this->actingAs($this->owner)->put(route('ideas.update', 999999), ideaPayload())->assertNotFound();

        expect(DB::table('ideas')->sole()->title)->toBe('Before');
    });
});

describe('deleting', function () {
    it('removes the idea and keeps the posts that were written from it', function (Role $role) {
        fakeChannelAdapter();

        $idea = ideaIn($this->workspace, $this->pillar);
        $post = postInState($this->workspace, PostState::Draft);

        DB::table('posts')->where('id', $post->id)->update(['idea_id' => $idea->id]);

        $this->actingAs(workspaceMember($this->workspace, $role))->get(route('ideas.index'))
            ->assertInertia(fn (Assert $page) => $page->where('ideas.0.posts_count', 1));

        $this->delete(route('ideas.destroy', $idea->id))->assertRedirect(route('ideas.index'));

        expect(DB::table('ideas')->count())->toBe(0)
            ->and(DB::table('posts')->where('id', $post->id)->sole()->idea_id)->toBeNull()
            ->and(DB::table('pillars')->count())->toBe(1);
    })->with(['owner' => Role::Owner, 'editor' => Role::Editor]);

    it('is refused for viewers', function () {
        $idea = ideaIn($this->workspace);

        $this->actingAs(workspaceMember($this->workspace, Role::Viewer))
            ->delete(route('ideas.destroy', $idea->id))
            ->assertForbidden();

        expect(DB::table('ideas')->count())->toBe(1);
    });
});

describe('another workspace', function () {
    beforeEach(function () {
        $this->idea = ideaIn($this->workspace, $this->pillar, ['title' => 'Harbour sunrise series', 'notes' => 'Ours only.']);
        $this->outsider = workspaceMember(Workspace::factory()->create(), Role::Owner);
    });

    it('does not see the idea or our pillars', function () {
        $response = $this->actingAs($this->outsider)->get(route('ideas.index'));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('ideas', [])
            ->where('pillars', []));

        expect($response->getContent())->not->toContain('Harbour sunrise series')->not->toContain('Ours only.');
    });

    it('cannot change or delete the idea, or use our pillar', function () {
        $this->actingAs($this->outsider)
            ->put(route('ideas.update', $this->idea->id), ideaPayload(['title' => 'Taken over']))
            ->assertNotFound();

        $this->delete(route('ideas.destroy', $this->idea->id))->assertNotFound();

        $this->post(route('ideas.store'), ideaPayload(['pillar_id' => $this->pillar->id]))
            ->assertSessionHasErrors('pillar_id');

        $row = DB::table('ideas')->sole();

        expect($row->title)->toBe('Harbour sunrise series')
            ->and($row->workspace_id)->toBe($this->workspace->id)
            ->and($row->pillar_id)->toBe($this->pillar->id);
    });
});
