<?php

use App\Domain\Channels\ChannelStatus;
use App\Domain\Channels\Contracts\ConnectedChannels;
use App\Domain\Channels\Exceptions\NothingToConnect;
use App\Domain\Channels\Models\Channel;
use App\Domain\Workspaces\Models\Membership;
use App\Domain\Workspaces\Models\Workspace;
use App\Domain\Workspaces\Role;
use App\Http\Controllers\Channels\ConnectChannelController;
use App\Http\Controllers\Channels\ConnectReport;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../../Support/channels.php';
require_once __DIR__.'/../../Support/facebook.php';
require_once __DIR__.'/../../Support/members.php';

/*
 * Facebook lists Pages in /me/accounts that cannot be connected: the role
 * does not allow publishing, or no Page token came with it. They are named to
 * the owner with the reason, on whichever page the connect ends.
 */

const ROLE_REASON = 'Your role on this Page does not allow creating posts. Change it under Page access in the settings of the Page.';
const ACCESS_REASON = 'Facebook gave no access to this Page.';
const MISSING_HINT = 'A Page you ticked on Facebook is not here? Facebook leaves out Pages that belong to a business portfolio unless the app also has the business_management permission. The README says how to add it.';

const SKIPPED_TWO = [
    ['id' => '100000000000003', 'name' => 'Read Only Page', 'reason' => ROLE_REASON],
    ['id' => '100000000000004', 'name' => 'Signal Hill Kitchen', 'reason' => ACCESS_REASON],
];

beforeEach(function () {
    configureFacebook();
    Http::preventStrayRequests();

    $this->workspace = Workspace::factory()->create(['timezone' => 'Europe/Copenhagen']);
    $this->owner = workspaceMember($this->workspace, Role::Owner);

    $this->logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) {
        $this->logged[] = $event->message.json_encode($event->context);
    });

    // The whole round trip. $accounts is a fixture name or a response for
    // /me/accounts, or the whole array for fakeGraph().
    $this->connect = function (mixed $accounts): TestResponse {
        fakeGraph(is_array($accounts) ? $accounts : ['accounts' => $accounts]);

        $this->actingAs($this->owner)->post(route('channels.connect', 'facebook'))->assertRedirect();

        return $this->get(route('channels.callback', [
            'platform' => 'facebook',
            'code' => 'test-auth-code',
            'state' => session(ConnectChannelController::PENDING)['state'],
        ]));
    };

    $this->assertNoCredentials = function (string ...$texts): void {
        foreach ([...$texts, json_encode(session()->all()), implode("\n", $this->logged)] as $text) {
            expect($text)
                ->not->toContain('test-page-token')
                ->not->toContain('test-long-lived')
                ->not->toContain('test-short-lived')
                ->not->toContain('test-auth-code')
                ->not->toContain(FACEBOOK_TEST_APP_SECRET);
        }
    };
});

describe('the adapter', function () {
    it('returns the Pages it left out with a reason, and only their id and name', function () {
        fakeGraph(['accounts' => 'me_accounts_one_page_two_skipped']);

        $channels = inWorkspace($this->workspace, fn () => facebookAdapter()->connect($this->workspace, 'test-auth-code'));

        expect($channels)->toBeInstanceOf(ConnectedChannels::class)
            ->and($channels)->toHaveCount(1)
            ->and($channels->first()->display_name)->toBe('Table Bay Coffee')
            ->and(array_map(fn ($account) => $account->toArray(), $channels->skipped()))->toBe(SKIPPED_TWO)
            ->and(DB::table('channels')->count())->toBe(1);
    });

    it('says what Facebook listed when no Page can be connected', function () {
        fakeGraph(['accounts' => 'me_accounts_none_connectable']);

        try {
            inWorkspace($this->workspace, fn () => facebookAdapter()->connect($this->workspace, 'test-auth-code'));
            $this->fail('Expected NothingToConnect.');
        } catch (NothingToConnect $exception) {
            expect(array_map(fn ($account) => $account->toArray(), $exception->skipped()))->toBe(SKIPPED_TWO)
                ->and($exception->getMessage())->toContain('no Page')
                ->and($exception->context()['skipped_accounts'])->toBe(2)
                ->and(json_encode($exception->context()))->not->toContain('test-page-token');
        }

        expect(DB::table('channels')->count())->toBe(0);
    });

    it('has nothing to report when every listed Page can be connected', function () {
        fakeGraph(['accounts' => 'me_accounts_one_page']);

        $channels = inWorkspace($this->workspace, fn () => facebookAdapter()->connect($this->workspace, 'test-auth-code'));

        expect($channels)->toBeInstanceOf(ConnectedChannels::class)
            ->and($channels->skipped())->toBe([]);
    });
});

describe('a connect that ends on the page for choosing', function () {
    beforeEach(function () {
        // Two Pages that can be connected, one with a role that cannot
        // publish, and one that came without a token.
        $pages = [...facebookFixture('me_accounts')['data'], facebookFixture('me_accounts_none_connectable')['data'][1]];

        $this->callback = ($this->connect)(Http::response(['data' => $pages]));
    });

    it('shows the Pages that were left out, each with its reason, and no token', function () {
        $this->callback->assertRedirect(route('channels.selection.edit'));

        $response = $this->get(route('channels.selection.edit'));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('channels/Select')
            ->has('channels', 2)
            ->where('connect_report', [
                'platform_label' => 'Facebook',
                'skipped' => SKIPPED_TWO,
                'hint' => MISSING_HINT,
            ]));

        ($this->assertNoCredentials)($response->getContent());

        // The two that were left out were never saved.
        expect(DB::table('channels')->pluck('external_id')->sort()->values()->all())
            ->toBe(['100000000000001', '100000000000002']);
    });

    it('keeps showing them while the choice is open, and leaves the Channels page to the choice', function () {
        $this->get(route('channels.index'))->assertInertia(fn (Assert $page) => $page
            ->where('selection_open', true)
            ->where('connect_report', null));

        $this->get(route('channels.selection.edit'))->assertInertia(fn (Assert $page) => $page
            ->where('connect_report.skipped', SKIPPED_TWO));
    });

    it('forgets them once the choice is made', function (Closure $choose) {
        $choose($this);

        expect(session()->has(ConnectReport::KEY))->toBeFalse();

        $this->get(route('channels.index'))->assertInertia(fn (Assert $page) => $page->where('connect_report', null));
    })->with([
        'some are kept' => [fn ($test) => $test->put(route('channels.selection.update'), [
            'keep' => [DB::table('channels')->where('external_id', '100000000000001')->value('id')],
        ])->assertRedirect(route('channels.index'))],
        'none is kept' => [fn ($test) => $test->delete(route('channels.selection.destroy'))->assertRedirect(route('channels.index'))],
    ]);
});

describe('a connect with one Page that can be connected', function () {
    it('connects it at once and shows what was left out on the Channels page', function () {
        ($this->connect)('me_accounts_one_page_two_skipped')
            ->assertRedirect(route('channels.index'))
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Table Bay Coffee is connected.']);

        $channel = inWorkspace($this->workspace, fn () => Channel::query()->sole());

        expect($channel->status)->toBe(ChannelStatus::Active)
            ->and($channel->external_id)->toBe('100000000000001');

        $response = $this->get(route('channels.index'));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('channels/Index')
            ->has('channels', 1)
            ->where('connect_report', [
                'platform_label' => 'Facebook',
                'skipped' => SKIPPED_TWO,
                'hint' => MISSING_HINT,
            ]));

        ($this->assertNoCredentials)($response->getContent());
    });

    it('shows it once', function () {
        ($this->connect)('me_accounts_one_page_two_skipped');

        $this->get(route('channels.index'))->assertInertia(fn (Assert $page) => $page->has('connect_report.skipped', 2));
        $this->get(route('channels.index'))->assertInertia(fn (Assert $page) => $page->where('connect_report', null));

        expect(session()->has(ConnectReport::KEY))->toBeFalse();
    });

    it('still says that Facebook can leave Pages out when nothing was skipped', function () {
        ($this->connect)('me_accounts_one_page')->assertRedirect(route('channels.index'));

        $this->get(route('channels.index'))->assertInertia(fn (Assert $page) => $page
            ->where('connect_report.skipped', [])
            ->where('connect_report.hint', MISSING_HINT));
    });

    it('does not show it to another workspace of the same user', function () {
        $other = Workspace::factory()->create(['timezone' => 'Africa/Johannesburg']);
        Membership::factory()->for($other)->for($this->owner)->role(Role::Owner)->create();

        ($this->connect)('me_accounts_one_page_two_skipped');

        $this->withSession(['workspace_id' => $other->id])
            ->get(route('channels.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('channels', 0)
                ->where('connect_report', null));

        // It was not used up either: it still belongs to the first workspace.
        $this->withSession(['workspace_id' => $this->workspace->id])
            ->get(route('channels.index'))
            ->assertInertia(fn (Assert $page) => $page->where('connect_report.skipped', SKIPPED_TWO));
    });

    it('is not shown to a member who cannot connect channels', function () {
        ($this->connect)('me_accounts_one_page_two_skipped');

        $entry = session(ConnectReport::KEY);

        $this->actingAs(workspaceMember($this->workspace, Role::Editor))
            ->withSession([ConnectReport::KEY => $entry])
            ->get(route('channels.index'))
            ->assertInertia(fn (Assert $page) => $page->where('connect_report', null));
    });

    it('is not shown when it is too old', function () {
        ($this->connect)('me_accounts_one_page_two_skipped');

        $this->travel(Channel::PENDING_MINUTES + 1)->minutes();

        $this->get(route('channels.index'))->assertInertia(fn (Assert $page) => $page->where('connect_report', null));
    });

    it('is replaced when a new connect starts', function () {
        ($this->connect)('me_accounts_one_page_two_skipped');

        $this->post(route('channels.connect', 'facebook'))->assertRedirect();

        expect(session()->has(ConnectReport::KEY))->toBeFalse();
    });
});

describe('a connect where no Page can be connected', function () {
    it('says so, and shows what Facebook listed and what it may have left out', function () {
        ($this->connect)('me_accounts_none_connectable')
            ->assertRedirect(route('channels.index'))
            ->assertInertiaFlash('toast.type', 'error')
            ->assertInertiaFlash('toast.message', 'Facebook returned no Page that this account can publish to. Choose at least one Page in the Facebook dialog, and make sure the account can create content on it.');

        $response = $this->get(route('channels.index'));

        $response->assertInertia(fn (Assert $page) => $page
            ->has('channels', 0)
            ->where('connect_report', [
                'platform_label' => 'Facebook',
                'skipped' => SKIPPED_TWO,
                'hint' => MISSING_HINT,
            ]));

        ($this->assertNoCredentials)($response->getContent());

        expect(DB::table('channels')->count())->toBe(0)
            ->and($this->logged)->not->toBeEmpty();
    });

    it('shows the line about Pages that are left out when Facebook listed nothing at all', function () {
        ($this->connect)('me_accounts_empty')->assertInertiaFlash('toast.type', 'error');

        $this->get(route('channels.index'))->assertInertia(fn (Assert $page) => $page
            ->where('connect_report.skipped', [])
            ->where('connect_report.hint', MISSING_HINT));
    });

    it('shows nothing of the kind after a failure that is not about Pages', function () {
        // The first connect leaves Pages out. The second is refused at the
        // code exchange, so it has nothing to say about Pages.
        $exchanges = 0;

        ($this->connect)(['accounts' => 'me_accounts_one_page_two_skipped', 'code' => function () use (&$exchanges) {
            return facebookResponse(++$exchanges === 1 ? 'oauth_code_exchange' : 'error_code_used', $exchanges === 1 ? 200 : 400);
        }])->assertInertiaFlash('toast.type', 'success');

        $this->post(route('channels.connect', 'facebook'))->assertRedirect();
        $this->get(route('channels.callback', [
            'platform' => 'facebook',
            'code' => 'test-auth-code',
            'state' => session(ConnectChannelController::PENDING)['state'],
        ]))->assertInertiaFlash('toast.type', 'error');

        $this->get(route('channels.index'))->assertInertia(fn (Assert $page) => $page->where('connect_report', null));
    });
});

it('has nothing to show for an adapter that reports nothing', function () {
    fakeChannelAdapter()->connectReturns(fn () => new Collection([
        Channel::factory()->pending()->create(['display_name' => 'Plain']),
    ]));

    $this->actingAs($this->owner)->post(route('channels.connect', 'facebook'))->assertRedirect();
    $this->get(route('channels.callback', [
        'platform' => 'facebook',
        'code' => 'test-auth-code',
        'state' => session(ConnectChannelController::PENDING)['state'],
    ]))->assertRedirect(route('channels.index'));

    expect(session()->has(ConnectReport::KEY))->toBeFalse();

    $this->get(route('channels.index'))->assertInertia(fn (Assert $page) => $page->where('connect_report', null));
});
