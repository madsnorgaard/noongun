<?php

/*
 * Approved posts as examples (issue #22), as the pages tell it: the plan
 * pages say that examples are sent and show a cost that counts them, and
 * the editor says how many earlier posts a draft was written with. No page
 * shows the texts themselves.
 */

use App\Domain\Drafting\GenerateDraftJob;
use App\Domain\Workspaces\Models\Workspace;
use App\Domain\Workspaces\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../../Support/publishing.php';
require_once __DIR__.'/../../Support/posts.php';
require_once __DIR__.'/../../Support/proposals.php';
require_once __DIR__.'/../../Support/examples.php';
require_once __DIR__.'/../../Support/sources.php';

beforeEach(function () {
    // A Wednesday. 11:00 in Copenhagen.
    $this->travelTo('2026-11-25 10:00:00');
    Queue::fake();

    fakeChannelAdapter();
    fakeChannelAdapter('linkedin');
    $this->generator = fakeDraftGenerator();
    $this->workspace = Workspace::factory()->create(['timezone' => 'Europe/Copenhagen']);
    $this->owner = userWithRole($this->workspace, Role::Owner);
    $this->page = channelIn($this->workspace, ['display_name' => 'Page Name Kept Private']);
    $this->brief = briefIn($this->workspace);
});

describe('the pages that show a plan', function () {
    beforeEach(function () {
        // A price at which a few hundred characters show in whole cents.
        config(['noongun.drafting.prices.'.DRAFT_TEST_PROVIDER.'.'.DRAFT_TEST_MODEL => ['input' => 2000.00, 'output' => 10.00]]);

        slotIn($this->workspace, $this->page, 1, '09:00');
        $this->idea = ideaIn($this->workspace, null, ['title' => 'How we roast']);

        $this->weekPage = fn () => $this->actingAs($this->owner)->get(route('proposals.create', ['week' => '2026-11-30']))->assertOk();
        $this->ideaPage = fn () => $this->actingAs($this->owner)->get(route('ideas.draft.create', ['idea' => $this->idea->id]))->assertOk();
    });

    it('say nothing about examples while the workspace has approved nothing', function () {
        ($this->weekPage)()->assertInertia(fn (Assert $page) => $page->where('examples', null));
        ($this->ideaPage)()->assertInertia(fn (Assert $page) => $page->where('examples', null));
    });

    it('say how many approved posts go along, and show a higher expected cost for it', function () {
        $week = ($this->weekPage)()->inertiaProps('cost.expected');
        $one = ($this->ideaPage)()->inertiaProps('cost.expected');

        approvedText($this->workspace, $this->page, str_repeat('An approved text. ', 50), '2026-11-01 08:00:00');

        ($this->weekPage)()->assertInertia(fn (Assert $page) => $page
            ->where('examples', ['per_channel' => 5, 'top_days' => null])
            ->where('cost.expected', fn (string $expected): bool => (float) $expected > (float) $week));

        ($this->ideaPage)()->assertInertia(fn (Assert $page) => $page
            ->where('examples', ['per_channel' => 5, 'top_days' => null])
            ->where('cost.expected', fn (string $expected): bool => (float) $expected > (float) $one));
    });

    it('say nothing when examples are turned off, and nothing about a text of a channel that is not drafted for', function () {
        $profile = channelIn($this->workspace, ['platform' => 'linkedin']);
        approvedText($this->workspace, $profile, 'For the profile.', '2026-11-01 08:00:00');

        // The week has a slot on the page only.
        ($this->weekPage)()->assertInertia(fn (Assert $page) => $page->where('examples', null));

        $this->actingAs($this->owner)->get(route('ideas.draft.create', ['idea' => $this->idea->id, 'channels' => [$this->page->id]]))
            ->assertInertia(fn (Assert $page) => $page->where('examples', null));
        $this->actingAs($this->owner)->get(route('ideas.draft.create', ['idea' => $this->idea->id, 'channels' => [$profile->id]]))
            ->assertInertia(fn (Assert $page) => $page->where('examples', ['per_channel' => 5, 'top_days' => null]));

        config(['noongun.drafting.examples.per_channel' => 0]);

        $this->actingAs($this->owner)->get(route('ideas.draft.create', ['idea' => $this->idea->id, 'channels' => [$profile->id]]))
            ->assertInertia(fn (Assert $page) => $page->where('examples', null));
    });

    it('show nothing of the texts themselves, only that they are sent', function () {
        approvedText($this->workspace, $this->page, 'A text that stays where it is.', '2026-11-01 08:00:00');

        expect((string) ($this->weekPage)()->getContent())->not->toContain('A text that stays where it is.')
            ->and((string) ($this->ideaPage)()->getContent())->not->toContain('A text that stays where it is.');
    });

    it('render the sentence the pages promise', function () {
        foreach (['proposals/Create.vue' => 'proposal-examples', 'ideas/Draft.vue' => 'draft-examples'] as $file => $mark) {
            $source = preg_replace('/\s+/', ' ', (string) file_get_contents(resource_path("js/pages/{$file}")));

            expect($source)
                ->toContain('data-test="'.$mark.'"')
                ->toContain('The last ${examples.per_channel} approved posts for each channel are sent as examples.')
                ->toContain('The last approved post for each channel is sent as an example.');
        }
    });
});

describe('the editor', function () {
    it('tells the editor how many earlier posts the draft was written with', function () {
        approvedText($this->workspace, $this->page, 'Approved first.', '2026-11-01 08:00:00');
        approvedText($this->workspace, $this->page, 'Approved second.', '2026-11-02 08:00:00');

        runDraftJob(new GenerateDraftJob($this->workspace->id, runForThePage()->id));

        $this->actingAs($this->owner)->get(route('posts.show', DB::table('posts')->whereNotNull('draft_run_id')->value('id')))
            ->assertInertia(fn (Assert $page) => $page->where('post.origin.examples', 2));

        expect((string) file_get_contents(resource_path('js/pages/posts/Edit.vue')))
            ->toContain('data-test="post-origin-examples"')
            ->toContain('Written with')
            ->toContain('earlier posts as examples');
    });

    it('says 0 in the editor for a draft that had none', function () {
        runDraftJob(new GenerateDraftJob($this->workspace->id, runForThePage()->id));

        $this->actingAs($this->owner)->get(route('posts.show', DB::table('posts')->whereNotNull('draft_run_id')->value('id')))
            ->assertInertia(fn (Assert $page) => $page->where('post.origin.examples', 0));
    });
});

describe('the code', function () {
    it('asks for the examples of a plan in the presenter, to say whether any would be sent', function () {
        expect(sourcesMatching('/SelectExamplePosts \$/', 'Http/'))->toBe(['Http/Presenters/ProposalPresenter.php']);
    });
});
