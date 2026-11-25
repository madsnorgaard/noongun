<?php

use App\Domain\Drafting\Actions\SaveBrief;
use App\Domain\Drafting\Exceptions\DraftingNotAllowed;
use App\Domain\Drafting\Exceptions\DraftingStateConflict;
use App\Domain\Drafting\Models\Brief;
use App\Domain\Drafting\VoiceRules;
use App\Domain\Workspaces\Models\Workspace;
use App\Domain\Workspaces\Role;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../../Support/publishing.php';

itIsolatesWorkspaces(fn () => Brief::factory()->create());

beforeEach(function () {
    $this->workspace = Workspace::factory()->create();
});

it('has no brief until one is saved', function () {
    expect(inWorkspace($this->workspace, fn () => Brief::current()))->toBeNull();
});

it('saves the first brief as version 1 and makes it current', function () {
    $author = User::factory()->create();

    $brief = inWorkspace($this->workspace, fn () => Brief::saveNewVersion(
        'Table Bay Coffee',
        "# Who we are\n\nWe roast coffee in Cape Town.",
        new VoiceRules(['No contractions.'], ['facebook' => ['Conversational.']]),
        [['text' => 'Book a tasting', 'url' => 'https://example.com/tasting']],
        $author,
    ));

    $current = inWorkspace($this->workspace, fn () => Brief::current());

    expect($current?->id)->toBe($brief->id)
        ->and($current->version)->toBe(1)
        ->and($current->is_current)->toBeTrue()
        ->and($current->title)->toBe('Table Bay Coffee')
        ->and($current->body)->toContain('We roast coffee')
        ->and($current->voice_rules)->toEqual(new VoiceRules(['No contractions.'], ['facebook' => ['Conversational.']]))
        ->and($current->calls_to_action)->toBe([['text' => 'Book a tasting', 'url' => 'https://example.com/tasting']])
        ->and($current->created_by)->toBe($author->id);
});

it('adds a version on every save and keeps the earlier ones as they were', function () {
    $first = briefIn($this->workspace, ['title' => 'First', 'body' => 'The first text.']);
    $second = briefIn($this->workspace, ['title' => 'Second', 'body' => 'The second text.']);
    $third = inWorkspace($this->workspace, fn () => Brief::saveNewVersion('Third', 'The third text.', new VoiceRules));

    $rows = DB::table('briefs')->where('workspace_id', $this->workspace->id)->orderBy('version')->get();

    expect($rows->pluck('version')->all())->toBe([1, 2, 3])
        ->and($rows->pluck('is_current')->all())->toBe([false, false, true])
        ->and($rows->pluck('title')->all())->toBe(['First', 'Second', 'Third'])
        ->and($rows->pluck('body')->all())->toBe(['The first text.', 'The second text.', 'The third text.'])
        ->and(inWorkspace($this->workspace, fn () => Brief::current())?->id)->toBe($third->id)
        ->and([$first->version, $second->version])->toBe([1, 2]);
});

it('numbers versions for each workspace by itself', function () {
    $other = Workspace::factory()->create();

    briefIn($this->workspace);
    briefIn($this->workspace);
    $theirs = briefIn($other);

    expect($theirs->version)->toBe(1)
        ->and(inWorkspace($other, fn () => Brief::current())?->id)->toBe($theirs->id)
        ->and(DB::table('briefs')->where('is_current', true)->count())->toBe(2);
});

it('cannot have two current versions in one workspace, because the database refuses it', function () {
    $first = briefIn($this->workspace);
    briefIn($this->workspace);

    expect(fn () => DB::table('briefs')->where('id', $first->id)->update(['is_current' => true]))
        ->toThrow(QueryException::class);
});

it('never changes a saved version', function (string $attribute, mixed $value) {
    $brief = briefIn($this->workspace);

    inWorkspace($this->workspace, function () use ($brief, $attribute, $value) {
        expect(fn () => $brief->forceFill([$attribute => $value])->save())->toThrow(DraftingStateConflict::class);
    });
})->with([
    'title' => ['title', 'Another title'],
    'body' => ['body', 'Another text.'],
    'voice rules' => ['voice_rules', ['general' => ['Shout.']]],
    'calls to action' => ['calls_to_action', [['text' => 'Buy', 'url' => null]]],
    'version' => ['version', 7],
    'current flag' => ['is_current', false],
]);

it('stores voice rules and calls to action as data, and drops what is not one', function () {
    $brief = briefIn($this->workspace, [
        'voice_rules' => ['general' => ['No contractions.', ''], 'platforms' => ['facebook' => ['Conversational.']]],
        'calls_to_action' => [
            ['text' => '  Book a tasting ', 'url' => ' https://example.com/tasting '],
            ['text' => 'Say hello', 'url' => ''],
            ['text' => '', 'url' => 'https://example.com/lost'],
            'not an entry',
        ],
    ]);

    $row = DB::table('briefs')->where('id', $brief->id)->first();

    expect(json_decode($row->voice_rules, true))->toBe(['general' => ['No contractions.'], 'platforms' => ['facebook' => ['Conversational.']]])
        ->and(json_decode($row->calls_to_action, true))->toBe([
            ['text' => 'Book a tasting', 'url' => 'https://example.com/tasting'],
            ['text' => 'Say hello', 'url' => null],
        ]);
});

describe('SaveBrief', function () {
    it('lets owners and editors save, and records who did', function (Role $role) {
        $actor = userWithRole($this->workspace, $role);

        $brief = inWorkspace($this->workspace, fn () => app(SaveBrief::class)->handle(
            $actor, 'Table Bay Coffee', 'We roast coffee.', new VoiceRules(['No contractions.']),
        ));

        expect($brief->version)->toBe(1)
            ->and($brief->created_by)->toBe($actor->id)
            ->and($brief->author?->is($actor))->toBeTrue();
    })->with([Role::Owner, Role::Editor]);

    it('refuses viewers and people from another workspace', function () {
        $viewer = userWithRole($this->workspace, Role::Viewer);
        $stranger = userWithRole(Workspace::factory()->create(), Role::Owner);

        inWorkspace($this->workspace, function () use ($viewer, $stranger) {
            foreach ([$viewer, $stranger, new User] as $actor) {
                expect(fn () => app(SaveBrief::class)->handle($actor, 'Title', 'Body', new VoiceRules))
                    ->toThrow(DraftingNotAllowed::class);
            }

            expect(Brief::current())->toBeNull();
        });
    });
});
