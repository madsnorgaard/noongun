<?php

/*
 * The page where an owner stores the workspace's API key for drafting. The
 * key is write-only: these tests hold that it reaches the database
 * encrypted and reaches nothing else - no page, no session, no error, no
 * log line.
 */

use App\Domain\Drafting\Generators\PrismDraftGenerator;
use App\Domain\Drafting\Models\InstanceLlmCredential;
use App\Domain\Drafting\Models\LlmCredential;
use App\Domain\Workspaces\Models\Workspace;
use App\Domain\Workspaces\Role;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Monolog\Handler\TestHandler;
use Prism\Prism\Facades\Prism;

require_once __DIR__.'/../../Support/members.php';

const PAGE_TEST_KEY = 'sk-ant-api03-PageTestKeyThatMustNeverLeak-0123456789-wxyz';

const PAGE_OTHER_KEY = 'sk-ant-api03-AnotherPageTestKeyNeverToLeak-9876543210-abcd';

beforeEach(function () {
    $this->travelTo('2026-11-25 10:00:00');

    config([
        'noongun.drafting.provider' => 'anthropic',
        'noongun.drafting.providers' => ['anthropic' => [
            'generator' => PrismDraftGenerator::class,
            'api_key' => null,
            'model' => 'configured-model',
        ]],
    ]);

    $this->workspace = Workspace::factory()->create(['timezone' => 'Africa/Johannesburg']);
    $this->owner = workspaceMember($this->workspace, Role::Owner);
});

function storedKey(Workspace $workspace, string $provider = 'anthropic'): ?LlmCredential
{
    return inWorkspace($workspace, fn () => LlmCredential::query()->where('provider', $provider)->first());
}

function storeKeyAs(User $user, mixed $key = PAGE_TEST_KEY): TestResponse
{
    return test()->actingAs($user)->from(route('llm-key.edit'))->put(route('llm-key.update'), ['api_key' => $key]);
}

/**
 * Everything the browser and the session hold after a response.
 */
function everythingSentBack(TestResponse $response): string
{
    return implode("\n", [
        (string) $response->getContent(),
        (string) $response->headers,
        (string) json_encode(session()->all()),
    ]);
}

describe('the page', function () {
    it('tells an owner that there is no key', function () {
        $this->actingAs($this->owner)->get(route('llm-key.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/Drafting')
                ->where('provider', ['name' => 'anthropic', 'label' => 'Anthropic'])
                ->where('available', false)
                ->where('key_source', null)
                ->where('credential', null)
                ->where('can.manage', true));
    });

    it('shows the last four characters and when the key was set, and never the key', function () {
        inWorkspace($this->workspace, fn () => LlmCredential::store('anthropic', PAGE_TEST_KEY));

        $response = $this->actingAs($this->owner)->get(route('llm-key.edit'));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('available', true)
            ->where('key_source', 'workspace')
            ->where('credential', ['last_four' => 'wxyz', 'set_at' => '2026-11-25T10:00:00+00:00'])
            ->where('can.manage', true));

        expect(everythingSentBack($response))->not->toContain(PAGE_TEST_KEY)->not->toContain('PageTestKey');
    });

    it('says when drafting uses the instance key', function (string $from) {
        $from === 'environment'
            ? config(['noongun.drafting.providers.anthropic.api_key' => PAGE_OTHER_KEY])
            : InstanceLlmCredential::store('anthropic', PAGE_OTHER_KEY);

        $response = $this->actingAs($this->owner)->get(route('llm-key.edit'));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('available', true)
            ->where('key_source', 'instance')
            ->where('credential', null));

        expect(everythingSentBack($response))->not->toContain(PAGE_OTHER_KEY)->not->toContain('abcd');
    })->with(['environment', 'database']);

    it('says the workspace key is used when both exist', function () {
        config(['noongun.drafting.providers.anthropic.api_key' => PAGE_OTHER_KEY]);
        inWorkspace($this->workspace, fn () => LlmCredential::store('anthropic', PAGE_TEST_KEY));

        $this->actingAs($this->owner)->get(route('llm-key.edit'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('key_source', 'workspace')
                ->where('credential.last_four', 'wxyz'));
    });

    it('tells editors and viewers whether drafting is available, and nothing about keys', function (Role $role, bool $withKey) {
        if ($withKey) {
            inWorkspace($this->workspace, fn () => LlmCredential::store('anthropic', PAGE_TEST_KEY));
        }

        $response = $this->actingAs(workspaceMember($this->workspace, $role))->get(route('llm-key.edit'));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('available', $withKey)
            ->where('key_source', null)
            ->where('credential', null)
            ->where('can.manage', false));

        expect(everythingSentBack($response))->not->toContain('wxyz')->not->toContain('PageTestKey');
    })->with([
        'editor, key set' => [Role::Editor, true],
        'editor, no key' => [Role::Editor, false],
        'viewer, key set' => [Role::Viewer, true],
        'viewer, no key' => [Role::Viewer, false],
    ]);

    it('says so when no provider is configured', function (array $config) {
        config($config);

        $this->actingAs($this->owner)->get(route('llm-key.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('provider', null)
                ->where('available', false)
                ->where('key_source', null));
    })->with([
        'no default provider' => [['noongun.drafting.provider' => null]],
        'a provider that is not listed' => [['noongun.drafting.provider' => 'unlisted']],
    ]);

    it('needs a signed in user', function () {
        $this->get(route('llm-key.edit'))->assertRedirect(route('login'));
        $this->put(route('llm-key.update'), ['api_key' => PAGE_TEST_KEY])->assertRedirect(route('login'));
        $this->delete(route('llm-key.destroy'))->assertRedirect(route('login'));
    });

    it('calls no provider', function () {
        $fake = Prism::fake();
        inWorkspace($this->workspace, fn () => LlmCredential::store('anthropic', PAGE_TEST_KEY));

        $this->actingAs($this->owner)->get(route('llm-key.edit'))->assertOk();
        storeKeyAs($this->owner, PAGE_OTHER_KEY)->assertRedirect();

        $fake->assertCallCount(0);
        Http::assertNothingSent();
    });
});

describe('storing a key', function () {
    it('stores the key encrypted for the configured provider', function () {
        $response = storeKeyAs($this->owner);

        $response->assertSessionHasNoErrors()->assertRedirect(route('llm-key.edit'));

        $row = DB::table('llm_credentials')->sole();

        expect($row->workspace_id)->toBe($this->workspace->id)
            ->and($row->provider)->toBe('anthropic')
            ->and($row->scope)->toBe('workspace')
            ->and($row->api_key)->not->toContain('PageTestKey')
            ->and(Crypt::decryptString($row->api_key))->toBe(PAGE_TEST_KEY);
    });

    it('replaces the key and keeps one row', function () {
        storeKeyAs($this->owner);
        $this->travel(1)->hours();
        storeKeyAs($this->owner, PAGE_OTHER_KEY)->assertSessionHasNoErrors();

        expect(DB::table('llm_credentials')->count())->toBe(1)
            ->and(storedKey($this->workspace)?->api_key)->toBe(PAGE_OTHER_KEY);

        $this->actingAs($this->owner)->get(route('llm-key.edit'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('credential', ['last_four' => 'abcd', 'set_at' => '2026-11-25T11:00:00+00:00']));
    });

    it('sends the key nowhere: not to the page, the session, the redirect or the next page', function () {
        $response = storeKeyAs($this->owner);

        expect(everythingSentBack($response))->not->toContain(PAGE_TEST_KEY)->not->toContain('PageTestKey');

        $next = $this->actingAs($this->owner)->get(route('llm-key.edit'));

        expect(everythingSentBack($next))->not->toContain(PAGE_TEST_KEY)->not->toContain('PageTestKey');
    });

    it('writes the key to no log', function () {
        config([
            'logging.default' => 'capture',
            'logging.channels.capture' => ['driver' => 'monolog', 'handler' => TestHandler::class],
        ]);

        $handler = Log::channel('capture')->getLogger()->getHandlers()[0];
        Log::info('The capture channel records.');

        storeKeyAs($this->owner);
        storeKeyAs($this->owner, PAGE_OTHER_KEY.' with a space');
        storeKeyAs(workspaceMember($this->workspace, Role::Editor), PAGE_OTHER_KEY);
        $this->actingAs($this->owner)->delete(route('llm-key.destroy'));

        expect($handler->getRecords())->not->toBeEmpty();

        foreach ($handler->getRecords() as $record) {
            expect(json_encode($record->toArray()))->not->toContain('PageTestKey');
        }
    });

    it('refuses what cannot be a key, without repeating it', function (mixed $key) {
        $response = storeKeyAs($this->owner, $key);

        $response->assertSessionHasErrors('api_key')->assertRedirect(route('llm-key.edit'));

        expect(DB::table('llm_credentials')->count())->toBe(0)
            ->and(session()->getOldInput())->toBe([])
            ->and(session()->hasOldInput('api_key'))->toBeFalse();

        if (is_string($key) && $key !== '') {
            expect(everythingSentBack($response))->not->toContain($key)->not->toContain('PageTestKey');
        }
    })->with([
        'nothing' => [''],
        'too short' => ['sk-ant-short'],
        'too long' => [str_repeat('k', 513)],
        'a space' => [PAGE_TEST_KEY.' extra'],
        'a line break, which would split the header' => [PAGE_TEST_KEY."\r\nX-Injected: 1"],
        'a tab inside' => [PAGE_TEST_KEY."\tX"],
        'a NUL inside' => [PAGE_TEST_KEY."\0X"],
        'not ASCII' => ['sk-ant-api03-PageTestKey-ключ-0123456789'],
        'a list' => [[PAGE_TEST_KEY]],
    ]);

    it('drops the white space a paste brings along', function () {
        storeKeyAs($this->owner, '  '.PAGE_TEST_KEY."\n")->assertSessionHasNoErrors();

        expect(storedKey($this->workspace)?->api_key)->toBe(PAGE_TEST_KEY);
    });

    it('keeps the stored key when a replacement is refused', function () {
        storeKeyAs($this->owner);
        storeKeyAs($this->owner, 'too short')->assertSessionHasErrors('api_key');

        expect(storedKey($this->workspace)?->api_key)->toBe(PAGE_TEST_KEY);
    });

    it('is for owners only', function (Role $role) {
        storeKeyAs(workspaceMember($this->workspace, $role))->assertForbidden();

        expect(DB::table('llm_credentials')->count())->toBe(0);
    })->with([Role::Editor, Role::Viewer]);

    it('refuses a key when no provider is configured', function () {
        config(['noongun.drafting.provider' => null]);

        storeKeyAs($this->owner)->assertSessionHasErrors('api_key');

        expect(DB::table('llm_credentials')->count())->toBe(0);
    });

    it('is rate limited', function () {
        foreach (range(1, 6) as $attempt) {
            storeKeyAs($this->owner, 'too short')->assertRedirect();
        }

        storeKeyAs($this->owner)->assertTooManyRequests();

        expect(DB::table('llm_credentials')->count())->toBe(0);
    });
});

describe('removing a key', function () {
    it('removes the key of the workspace', function () {
        storeKeyAs($this->owner);

        $this->actingAs($this->owner)->delete(route('llm-key.destroy'))->assertRedirect(route('llm-key.edit'));

        expect(DB::table('llm_credentials')->count())->toBe(0);

        $this->actingAs($this->owner)->get(route('llm-key.edit'))
            ->assertInertia(fn (Assert $page) => $page->where('credential', null)->where('available', false));
    });

    it('falls back to the instance key, and leaves it alone', function () {
        InstanceLlmCredential::store('anthropic', PAGE_OTHER_KEY);
        storeKeyAs($this->owner);

        $this->actingAs($this->owner)->delete(route('llm-key.destroy'))->assertRedirect();

        expect(DB::table('llm_credentials')->sole()->scope)->toBe('instance');

        $this->actingAs($this->owner)->get(route('llm-key.edit'))
            ->assertInertia(fn (Assert $page) => $page->where('key_source', 'instance')->where('available', true));
    });

    it('does nothing when there is no key', function () {
        $this->actingAs($this->owner)->delete(route('llm-key.destroy'))->assertRedirect(route('llm-key.edit'));
    });

    it('is for owners only', function (Role $role) {
        storeKeyAs($this->owner);

        $this->actingAs(workspaceMember($this->workspace, $role))->delete(route('llm-key.destroy'))->assertForbidden();

        expect(storedKey($this->workspace))->not->toBeNull();
    })->with([Role::Editor, Role::Viewer]);
});

describe('another workspace', function () {
    beforeEach(function () {
        $this->other = Workspace::factory()->create();
        $this->otherOwner = workspaceMember($this->other, Role::Owner);

        inWorkspace($this->other, fn () => LlmCredential::store('anthropic', PAGE_OTHER_KEY));
    });

    it('does not show its key', function () {
        $response = $this->actingAs($this->owner)->get(route('llm-key.edit'));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('available', false)
            ->where('key_source', null)
            ->where('credential', null));

        expect(everythingSentBack($response))->not->toContain('abcd');
    });

    it('is not written to when a key is stored', function () {
        storeKeyAs($this->owner);

        expect(storedKey($this->other)?->api_key)->toBe(PAGE_OTHER_KEY)
            ->and(storedKey($this->workspace)?->api_key)->toBe(PAGE_TEST_KEY)
            ->and(DB::table('llm_credentials')->count())->toBe(2);
    });

    it('keeps its key when a key is removed', function () {
        storeKeyAs($this->owner);

        $this->actingAs($this->owner)->delete(route('llm-key.destroy'));

        expect(storedKey($this->other)?->api_key)->toBe(PAGE_OTHER_KEY)
            ->and(storedKey($this->workspace))->toBeNull();
    });

    it('cannot be reached by naming it in the session', function () {
        $this->actingAs($this->owner)->withSession(['workspace_id' => $this->other->id])
            ->delete(route('llm-key.destroy'));

        expect(storedKey($this->other)?->api_key)->toBe(PAGE_OTHER_KEY);
    });
});
