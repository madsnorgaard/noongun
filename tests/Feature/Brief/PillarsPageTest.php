<?php

use App\Domain\Content\PostState;
use App\Domain\Workspaces\Models\Workspace;
use App\Domain\Workspaces\Role;
use App\Http\Requests\Brief\SavePillarRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../../Support/members.php';
require_once __DIR__.'/../../Support/publishing.php';

beforeEach(function () {
    $this->workspace = Workspace::factory()->create(['timezone' => 'Europe/Copenhagen']);
    $this->owner = workspaceMember($this->workspace, Role::Owner);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function pillarPayload(array $overrides = []): array
{
    return ['name' => 'Behind the roast', 'description' => 'How the coffee is made.', 'weekly_target' => 2, ...$overrides];
}

describe('list', function () {
    it('shows the pillars with their targets and what refers to them', function (Role $role, bool $mayManage) {
        $roast = pillarIn($this->workspace, ['name' => 'Roast', 'description' => 'How it is made.', 'weekly_target' => 2]);
        pillarIn($this->workspace, ['name' => 'Cafe', 'description' => null, 'weekly_target' => 0]);
        ideaIn($this->workspace, $roast);
        ideaIn($this->workspace, $roast);
        ideaIn($this->workspace);

        $this->actingAs(workspaceMember($this->workspace, $role))->get(route('pillars.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('brief/Pillars')
                ->has('pillars', 2)
                ->where('pillars.0.name', 'Cafe')
                ->where('pillars.0.description', null)
                ->where('pillars.0.weekly_target', 0)
                ->where('pillars.0.ideas_count', 0)
                ->where('pillars.1', [
                    'id' => $roast->id,
                    'name' => 'Roast',
                    'description' => 'How it is made.',
                    'weekly_target' => 2,
                    'ideas_count' => 2,
                    'posts_count' => 0,
                ])
                ->where('limits.weekly_target', SavePillarRequest::MAX_WEEKLY_TARGET)
                ->where('can.manage', $mayManage));
    })->with([
        'owner' => [Role::Owner, true],
        'editor' => [Role::Editor, true],
        'viewer' => [Role::Viewer, false],
    ]);

    it('is not shown to someone without a workspace, and guests are sent to the login page', function () {
        $this->actingAs(User::factory()->create())->get(route('pillars.index'))->assertForbidden();

        auth()->logout();

        $this->get(route('pillars.index'))->assertRedirect(route('login'));
    });
});

describe('creating', function () {
    it('adds a pillar to the workspace', function (Role $role) {
        $this->actingAs(workspaceMember($this->workspace, $role))
            ->post(route('pillars.store'), pillarPayload(['name' => '  Behind the roast  ', 'description' => '']))
            ->assertRedirect(route('pillars.index'))
            ->assertSessionHasNoErrors();

        $row = DB::table('pillars')->sole();

        expect($row->workspace_id)->toBe($this->workspace->id)
            ->and($row->name)->toBe('Behind the roast')
            ->and($row->description)->toBeNull()
            ->and($row->weekly_target)->toBe(2);
    })->with(['owner' => Role::Owner, 'editor' => Role::Editor]);

    it('refuses what is missing, too long or out of range', function (array $payload, string $field) {
        pillarIn($this->workspace, ['name' => 'Taken']);

        $this->actingAs($this->owner)
            ->post(route('pillars.store'), pillarPayload($payload))
            ->assertSessionHasErrors($field);

        expect(DB::table('pillars')->count())->toBe(1);
    })->with([
        'no name' => [['name' => ''], 'name'],
        'a long name' => [['name' => str_repeat('a', SavePillarRequest::MAX_NAME_LENGTH + 1)], 'name'],
        'a name that is in use' => [['name' => 'Taken'], 'name'],
        'a long description' => [['description' => str_repeat('a', SavePillarRequest::MAX_DESCRIPTION_LENGTH + 1)], 'description'],
        'no target' => [['weekly_target' => null], 'weekly_target'],
        'a negative target' => [['weekly_target' => -1], 'weekly_target'],
        'a target that is not a whole number' => [['weekly_target' => '1.5'], 'weekly_target'],
        'a target that is too high' => [['weekly_target' => SavePillarRequest::MAX_WEEKLY_TARGET + 1], 'weekly_target'],
    ]);

    it('allows a name that another workspace uses', function () {
        pillarIn(Workspace::factory()->create(), ['name' => 'Behind the roast']);

        $this->actingAs($this->owner)->post(route('pillars.store'), pillarPayload())->assertSessionHasNoErrors();

        expect(DB::table('pillars')->where('workspace_id', $this->workspace->id)->count())->toBe(1);
    });

    it('is refused for viewers', function () {
        $this->actingAs(workspaceMember($this->workspace, Role::Viewer))
            ->post(route('pillars.store'), pillarPayload())
            ->assertForbidden();

        expect(DB::table('pillars')->count())->toBe(0);
    });
});

describe('changing', function () {
    beforeEach(function () {
        $this->pillar = pillarIn($this->workspace, ['name' => 'Roast', 'weekly_target' => 1]);
    });

    it('renames, describes and sets the weekly target', function (Role $role) {
        $this->actingAs(workspaceMember($this->workspace, $role))
            ->put(route('pillars.update', $this->pillar->id), ['name' => 'Roastery', 'description' => 'New words.', 'weekly_target' => 0])
            ->assertRedirect(route('pillars.index'))
            ->assertSessionHasNoErrors();

        $row = DB::table('pillars')->sole();

        expect($row->name)->toBe('Roastery')
            ->and($row->description)->toBe('New words.')
            ->and($row->weekly_target)->toBe(0);
    })->with(['owner' => Role::Owner, 'editor' => Role::Editor]);

    it('keeps its own name without a complaint and refuses the name of another pillar', function () {
        pillarIn($this->workspace, ['name' => 'Cafe']);

        $this->actingAs($this->owner)
            ->put(route('pillars.update', $this->pillar->id), pillarPayload(['name' => 'Roast']))
            ->assertSessionHasNoErrors();

        $this->put(route('pillars.update', $this->pillar->id), pillarPayload(['name' => 'Cafe']))
            ->assertSessionHasErrors('name');
    });

    it('is refused for viewers and answers 404 for a pillar that does not exist', function () {
        $this->actingAs(workspaceMember($this->workspace, Role::Viewer))
            ->put(route('pillars.update', $this->pillar->id), pillarPayload())
            ->assertForbidden();

        $this->actingAs($this->owner)->put(route('pillars.update', 999999), pillarPayload())->assertNotFound();
        $this->put('/brief/pillars/roast', pillarPayload())->assertNotFound();

        expect(DB::table('pillars')->sole()->name)->toBe('Roast');
    });
});

describe('deleting', function () {
    it('removes the pillar and keeps its ideas and posts without a pillar', function (Role $role) {
        fakeChannelAdapter();

        $pillar = pillarIn($this->workspace, ['name' => 'Roast']);
        $idea = ideaIn($this->workspace, $pillar);
        $post = postInState($this->workspace, PostState::Draft);

        DB::table('posts')->where('id', $post->id)->update(['pillar_id' => $pillar->id, 'idea_id' => $idea->id]);

        $this->actingAs(workspaceMember($this->workspace, $role))->get(route('pillars.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('pillars.0.ideas_count', 1)
                ->where('pillars.0.posts_count', 1));

        $this->delete(route('pillars.destroy', $pillar->id))->assertRedirect(route('pillars.index'));

        expect(DB::table('pillars')->count())->toBe(0)
            ->and(DB::table('ideas')->where('id', $idea->id)->sole()->pillar_id)->toBeNull()
            ->and(DB::table('posts')->where('id', $post->id)->sole()->pillar_id)->toBeNull()
            ->and(DB::table('posts')->where('id', $post->id)->sole()->idea_id)->toBe($idea->id);
    })->with(['owner' => Role::Owner, 'editor' => Role::Editor]);

    it('is refused for viewers', function () {
        $pillar = pillarIn($this->workspace);

        $this->actingAs(workspaceMember($this->workspace, Role::Viewer))
            ->delete(route('pillars.destroy', $pillar->id))
            ->assertForbidden();

        expect(DB::table('pillars')->count())->toBe(1);
    });
});

describe('another workspace', function () {
    beforeEach(function () {
        $this->pillar = pillarIn($this->workspace, ['name' => 'Harbour notes', 'weekly_target' => 3]);
        $this->outsider = workspaceMember(Workspace::factory()->create(), Role::Owner);
    });

    it('does not see the pillar', function () {
        $response = $this->actingAs($this->outsider)->get(route('pillars.index'));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page->where('pillars', []));

        expect($response->getContent())->not->toContain('Harbour notes');
    });

    it('cannot change or delete the pillar', function () {
        $this->actingAs($this->outsider)
            ->put(route('pillars.update', $this->pillar->id), pillarPayload(['name' => 'Theirs']))
            ->assertNotFound();

        $this->delete(route('pillars.destroy', $this->pillar->id))->assertNotFound();

        $row = DB::table('pillars')->sole();

        expect($row->name)->toBe('Harbour notes')
            ->and($row->weekly_target)->toBe(3)
            ->and($row->workspace_id)->toBe($this->workspace->id);
    });
});
