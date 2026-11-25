<?php

use App\Domain\Drafting\Actions\SaveBrief;
use App\Domain\Drafting\Models\Brief;
use App\Domain\Drafting\VoiceRules;
use App\Domain\Workspaces\Models\Workspace;
use App\Domain\Workspaces\Role;
use App\Http\Requests\Brief\SaveBriefRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../../Support/members.php';

beforeEach(function () {
    $this->travelTo('2026-11-25 10:00:00');

    $this->workspace = Workspace::factory()->create(['timezone' => 'Europe/Copenhagen']);
    $this->owner = workspaceMember($this->workspace, Role::Owner);
});

/**
 * What the brief page sends.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function briefPayload(array $overrides = []): array
{
    return [
        'title' => 'Table Bay Coffee',
        'body' => "We roast coffee in Cape Town.\n\n## Offer\n\nBeans by post.",
        'voice_rules' => [
            'general' => ['No contractions.'],
            'platforms' => ['facebook' => ['Personal and conversational.']],
        ],
        'calls_to_action' => [
            ['text' => 'Order beans', 'url' => 'https://example.com/shop'],
            ['text' => 'Visit the roastery', 'url' => null],
        ],
        ...$overrides,
    ];
}

/**
 * @return list<stdClass>
 */
function briefRows(Workspace $workspace): array
{
    return DB::table('briefs')->where('workspace_id', $workspace->id)->orderBy('version')->get()->all();
}

describe('the page', function () {
    it('starts empty and offers the house voice rules without applying them', function (Role $role, bool $mayUpdate) {
        $this->actingAs(workspaceMember($this->workspace, $role))->get(route('brief.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('brief/Edit')
                ->where('brief', null)
                ->where('versions', [])
                ->where('version_count', 0)
                ->where('starter_voice.general', ['No contractions.', 'Short hyphens instead of em dashes.'])
                ->where('starter_voice.platforms.facebook.0', 'Personal and conversational, like old school Facebook. No hashtag walls.')
                ->where('starter_voice.platforms.linkedin', ['Professional but human.'])
                ->where('platforms', [
                    ['name' => 'facebook', 'label' => 'Facebook'],
                    ['name' => 'linkedin', 'label' => 'LinkedIn'],
                ])
                ->where('limits.body', SaveBriefRequest::MAX_BODY_LENGTH)
                ->where('can.update', $mayUpdate));

        expect(briefRows($this->workspace))->toBeEmpty();
    })->with([
        'owner' => [Role::Owner, true],
        'editor' => [Role::Editor, true],
        'viewer' => [Role::Viewer, false],
    ]);

    it('shows the current version with who saved it and when', function () {
        $this->owner->update(['name' => 'Ada Owner']);

        inWorkspace($this->workspace, fn () => Brief::saveNewVersion('Old title', 'Old text', new VoiceRules));

        $this->travelTo('2026-11-25 22:30:00');
        $this->actingAs($this->owner)->put(route('brief.update'), briefPayload());

        $this->get(route('brief.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('brief.version', 2)
                ->where('brief.is_current', true)
                ->where('brief.title', 'Table Bay Coffee')
                ->where('brief.body', "We roast coffee in Cape Town.\n\n## Offer\n\nBeans by post.")
                ->where('brief.voice_rules.general', ['No contractions.'])
                ->where('brief.voice_rules.platforms.facebook', ['Personal and conversational.'])
                ->where('brief.calls_to_action', [
                    ['text' => 'Order beans', 'url' => 'https://example.com/shop'],
                    ['text' => 'Visit the roastery', 'url' => null],
                ])
                // An instant in UTC. The page shows it on the clock of the
                // workspace, where it is already the next day.
                ->where('brief.saved_at', '2026-11-25T22:30:00+00:00')
                ->where('brief.saved_by', 'Ada Owner')
                ->where('version_count', 2)
                ->where('versions', [
                    ['version' => 2, 'is_current' => true, 'saved_at' => '2026-11-25T22:30:00+00:00', 'saved_by' => 'Ada Owner'],
                    ['version' => 1, 'is_current' => false, 'saved_at' => '2026-11-25T10:00:00+00:00', 'saved_by' => null],
                ])
                ->where('workspace.timezone', 'Europe/Copenhagen'));
    });

    it('gives the timezone of the workspace for the times it shows', function (string $timezone) {
        $workspace = Workspace::factory()->create(['timezone' => $timezone]);

        $this->actingAs(workspaceMember($workspace))->get(route('brief.edit'))
            ->assertInertia(fn (Assert $page) => $page->where('workspace.timezone', $timezone));
    })->with(['Europe/Copenhagen', 'Africa/Johannesburg']);

    it('keeps a list of rules for a platform the brief already names', function () {
        briefIn($this->workspace, ['voice_rules' => ['general' => [], 'platforms' => ['mastodon' => ['Short.']]]]);

        $this->actingAs($this->owner)->get(route('brief.edit'))
            ->assertInertia(fn (Assert $page) => $page->where('platforms.2', ['name' => 'mastodon', 'label' => 'Mastodon']));
    });

    it('is not shown to someone without a workspace, and guests are sent to the login page', function () {
        $this->actingAs(User::factory()->create())->get(route('brief.edit'))->assertForbidden();

        auth()->logout();

        $this->get(route('brief.edit'))->assertRedirect(route('login'));
    });
});

describe('saving', function () {
    it('creates a new version every time and leaves earlier versions as they were', function (Role $role) {
        $member = workspaceMember($this->workspace, $role);

        $this->actingAs($member)->put(route('brief.update'), briefPayload())
            ->assertRedirect(route('brief.edit'))
            ->assertSessionHasNoErrors();

        $this->put(route('brief.update'), briefPayload(['title' => 'Table Bay Coffee Roasters']))
            ->assertRedirect(route('brief.edit'));

        $rows = briefRows($this->workspace);

        expect($rows)->toHaveCount(2)
            ->and($rows[0]->version)->toBe(1)
            ->and($rows[0]->title)->toBe('Table Bay Coffee')
            ->and($rows[0]->is_current)->toBeFalse()
            ->and($rows[1]->version)->toBe(2)
            ->and($rows[1]->title)->toBe('Table Bay Coffee Roasters')
            ->and($rows[1]->is_current)->toBeTrue()
            ->and($rows[1]->created_by)->toBe($member->id)
            ->and(json_decode($rows[1]->voice_rules, true))->toBe([
                'general' => ['No contractions.'],
                'platforms' => ['facebook' => ['Personal and conversational.']],
            ]);
    })->with(['owner' => Role::Owner, 'editor' => Role::Editor]);

    it('does not add a version when nothing changed', function () {
        $this->actingAs($this->owner)->put(route('brief.update'), briefPayload());
        $this->put(route('brief.update'), briefPayload())->assertRedirect(route('brief.edit'));

        expect(briefRows($this->workspace))->toHaveCount(1);
    });

    it('locks the workspace before it reads or writes a brief, so two saves at once cannot take the same version', function (bool $first) {
        if (! $first) {
            briefIn($this->workspace);
        }

        $statements = [];
        DB::listen(function ($query) use (&$statements) {
            $statements[] = strtolower($query->sql);
        });

        // The first save has no brief row to lock, so the lock is on the
        // workspace. The comparison with the current version and the next
        // version number are both read after it.
        $this->actingAs($this->owner)->put(route('brief.update'), briefPayload())->assertSessionHasNoErrors();

        $positions = fn (Closure $matches): array => array_keys(array_filter($statements, $matches));

        $lock = $positions(fn (string $sql) => str_contains($sql, '"workspaces"') && str_contains($sql, 'for update'))[0] ?? null;
        // The request reads the brief once before, only to know which
        // platforms may have rules. What decides the save is read after.
        $compares = $positions(fn (string $sql) => str_starts_with($sql, 'select') && str_contains($sql, 'from "briefs"') && str_contains($sql, '"is_current"'));
        $numbers = $positions(fn (string $sql) => str_contains($sql, 'max("version")'));
        $writes = $positions(fn (string $sql) => str_starts_with($sql, 'insert into "briefs"') || str_starts_with($sql, 'update "briefs"'));

        expect($lock)->not->toBeNull()
            ->and(max($compares))->toBeGreaterThan($lock)
            ->and($numbers)->toHaveCount(1)
            ->and($numbers[0])->toBeGreaterThan($lock)
            ->and(min($writes))->toBeGreaterThan($lock)
            ->and(array_filter($statements, fn (string $sql) => str_contains($sql, 'from "briefs"') && str_contains($sql, 'for update')))->toBeEmpty();
    })->with(['the first save' => true, 'a later save' => false]);

    it('adds one version when the same save arrives twice, as the second finds the first under the lock', function () {
        $save = fn () => inWorkspace($this->workspace, fn () => app(SaveBrief::class)->handle(
            $this->owner, 'Table Bay Coffee', 'We roast coffee.', new VoiceRules(['No contractions.']), [['text' => 'Order', 'url' => '']], onlyIfChanged: true,
        ));

        $one = $save();
        $two = $save();

        expect($one->wasRecentlyCreated)->toBeTrue()
            ->and($two->wasRecentlyCreated)->toBeFalse()
            ->and($two->id)->toBe($one->id)
            ->and(briefRows($this->workspace))->toHaveCount(1);
    });

    it('stores the text as it was typed, markup included, and drops empty rules', function () {
        $body = "<script>alert(1)</script>\n\n**Bold** and <b>tags</b> stay text.";

        $this->actingAs($this->owner)->put(route('brief.update'), briefPayload([
            'body' => $body,
            'voice_rules' => [
                'general' => ['No contractions.', '', '   ', 'No contractions.'],
                'platforms' => ['facebook' => [''], 'linkedin' => ['Professional but human.']],
            ],
            'calls_to_action' => [],
        ]))->assertSessionHasNoErrors();

        $row = briefRows($this->workspace)[0];

        expect($row->body)->toBe($body)
            ->and(json_decode($row->voice_rules, true))->toBe([
                'general' => ['No contractions.'],
                'platforms' => ['linkedin' => ['Professional but human.']],
            ])
            ->and(json_decode($row->calls_to_action, true))->toBe([]);
    });

    it('never calls out, also when a call to action has a link', function () {
        Http::fake();

        $this->actingAs($this->owner)->put(route('brief.update'), briefPayload());

        Http::assertNothingSent();
    });

    it('refuses what is missing, too long or not a web address', function (Closure $payload, string $field) {
        $this->actingAs($this->owner)
            ->from(route('brief.edit'))
            ->put(route('brief.update'), briefPayload($payload()))
            ->assertRedirect(route('brief.edit'))
            ->assertSessionHasErrors($field);

        expect(briefRows($this->workspace))->toBeEmpty();
    })->with([
        'no title' => [fn () => ['title' => ''], 'title'],
        'a long title' => [fn () => ['title' => str_repeat('a', SaveBriefRequest::MAX_TITLE_LENGTH + 1)], 'title'],
        'no text' => [fn () => ['body' => ''], 'body'],
        'a text that is too long' => [fn () => ['body' => str_repeat('a', SaveBriefRequest::MAX_BODY_LENGTH + 1)], 'body'],
        'voice rules that are not lists' => [fn () => ['voice_rules' => 'be nice'], 'voice_rules'],
        'a general rule that is not text' => [fn () => ['voice_rules' => ['general' => [['nested']], 'platforms' => []]], 'voice_rules.general.0'],
        'a rule that is too long' => [fn () => ['voice_rules' => ['general' => [str_repeat('a', SaveBriefRequest::MAX_RULE_LENGTH + 1)], 'platforms' => []]], 'voice_rules.general.0'],
        'too many rules' => [fn () => ['voice_rules' => ['general' => array_fill(0, SaveBriefRequest::MAX_RULES + 1, 'Rule.'), 'platforms' => []]], 'voice_rules.general'],
        'rules for an unknown platform' => [fn () => ['voice_rules' => ['general' => [], 'platforms' => ['<script>' => ['x']]]], 'voice_rules.platforms'],
        'a platform rule that is too long' => [fn () => ['voice_rules' => ['general' => [], 'platforms' => ['facebook' => [str_repeat('a', SaveBriefRequest::MAX_RULE_LENGTH + 1)]]]], 'voice_rules.platforms.facebook.0'],
        'a call to action without text' => [fn () => ['calls_to_action' => [['text' => '', 'url' => 'https://example.com']]], 'calls_to_action.0.text'],
        'a call to action that is too long' => [fn () => ['calls_to_action' => [['text' => str_repeat('a', SaveBriefRequest::MAX_CALL_LENGTH + 1), 'url' => null]]], 'calls_to_action.0.text'],
        'a script address' => [fn () => ['calls_to_action' => [['text' => 'Go', 'url' => 'javascript:alert(1)']]], 'calls_to_action.0.url'],
        'an ftp address' => [fn () => ['calls_to_action' => [['text' => 'Go', 'url' => 'ftp://example.com/file']]], 'calls_to_action.0.url'],
        'an address that is too long' => [fn () => ['calls_to_action' => [['text' => 'Go', 'url' => 'https://example.com/'.str_repeat('a', 2048)]]], 'calls_to_action.0.url'],
        'too many calls to action' => [fn () => ['calls_to_action' => array_fill(0, SaveBriefRequest::MAX_CALLS_TO_ACTION + 1, ['text' => 'Go', 'url' => null])], 'calls_to_action'],
    ]);

    it('is refused for viewers, for people without a workspace and for guests', function () {
        $this->actingAs(workspaceMember($this->workspace, Role::Viewer))
            ->put(route('brief.update'), briefPayload())
            ->assertForbidden();

        $this->actingAs(User::factory()->create())
            ->put(route('brief.update'), briefPayload())
            ->assertForbidden();

        auth()->logout();

        $this->put(route('brief.update'), briefPayload())->assertRedirect(route('login'));

        expect(DB::table('briefs')->count())->toBe(0);
    });
});

describe('earlier versions', function () {
    beforeEach(function () {
        $this->first = briefIn($this->workspace, ['title' => 'First', 'body' => 'The first text.']);
        $this->second = briefIn($this->workspace, ['title' => 'Second']);
    });

    it('can be read by every member', function (Role $role) {
        $this->actingAs(workspaceMember($this->workspace, $role))
            ->get(route('brief.versions.show', 1))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('brief/Version')
                ->where('brief.version', 1)
                ->where('brief.is_current', false)
                ->where('brief.title', 'First')
                ->where('brief.body', 'The first text.'));
    })->with(['owner' => Role::Owner, 'editor' => Role::Editor, 'viewer' => Role::Viewer]);

    it('answers 404 for a version that does not exist', function () {
        $this->actingAs($this->owner)->get(route('brief.versions.show', 3))->assertNotFound();
        $this->get('/brief/versions/first')->assertNotFound();
    });

    it('cannot be restored or changed: there is no such route', function () {
        $this->actingAs($this->owner);

        $this->post('/brief/versions/1')->assertStatus(405);
        $this->put('/brief/versions/1', briefPayload())->assertStatus(405);
        $this->delete('/brief/versions/1')->assertStatus(405);
        $this->post('/brief/versions/1/restore')->assertNotFound();

        expect(briefRows($this->workspace))->toHaveCount(2)
            ->and(briefRows($this->workspace)[0]->title)->toBe('First');
    });
});

describe('another workspace', function () {
    beforeEach(function () {
        $this->brief = briefIn($this->workspace, ['title' => 'Ours', 'body' => 'Our secret plan.']);
        $this->elsewhere = Workspace::factory()->create();
        $this->outsider = workspaceMember($this->elsewhere, Role::Owner);
    });

    it('does not see the brief or its versions', function () {
        $response = $this->actingAs($this->outsider)->get(route('brief.edit'));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('brief', null)
            ->where('versions', [])
            ->where('version_count', 0));

        expect($response->getContent())->not->toContain('Our secret plan.');

        $this->get(route('brief.versions.show', 1))->assertNotFound();
    });

    it('reads its own version under the same number', function () {
        briefIn($this->elsewhere, ['title' => 'Theirs']);

        $response = $this->actingAs($this->outsider)->get(route('brief.versions.show', 1));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page->where('brief.title', 'Theirs'));

        expect($response->getContent())->not->toContain('Our secret plan.');
    });

    it('saves into its own workspace and leaves ours alone', function () {
        $this->actingAs($this->outsider)->put(route('brief.update'), briefPayload(['title' => 'Theirs']));

        $ours = briefRows($this->workspace);
        $theirs = briefRows($this->elsewhere);

        expect($ours)->toHaveCount(1)
            ->and($ours[0]->title)->toBe('Ours')
            ->and($ours[0]->is_current)->toBeTrue()
            ->and($theirs)->toHaveCount(1)
            ->and($theirs[0]->version)->toBe(1)
            ->and($theirs[0]->title)->toBe('Theirs');
    });
});

it('never renders text from a user as markup on the brief, pillar, slot and idea pages', function () {
    $files = [
        ...glob(resource_path('js/pages/brief/*.vue')),
        ...glob(resource_path('js/pages/ideas/*.vue')),
        ...glob(resource_path('js/components/brief/*.vue')),
    ];

    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        expect(file_get_contents($file))->not->toContain('v-html')->not->toContain('innerHTML');
    }
});

it('leads from the setup pages on to proposing a week', function () {
    expect(Route::has('proposals.create'))->toBeTrue();

    foreach (['components/brief/BriefTabs.vue', 'pages/brief/Edit.vue', 'pages/settings/Drafting.vue', 'pages/Dashboard.vue'] as $file) {
        $source = (string) file_get_contents(resource_path('js/'.$file));

        expect($source)->toContain("from '@/routes/proposals'")->toContain(':href="proposeWeek()"');
        // Proposing is never the red action of these pages.
        expect($source)->not->toContain('variant="signal"');
    }

    foreach (['Edit' => 'can.update', 'Pillars' => 'can.manage', 'Slots' => 'can.manage'] as $page => $ability) {
        expect((string) file_get_contents(resource_path("js/pages/brief/{$page}.vue")))->toContain(':can-propose="'.$ability.'"');
    }
});
