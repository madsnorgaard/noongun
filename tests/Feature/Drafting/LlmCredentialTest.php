<?php

use App\Domain\Drafting\CredentialScope;
use App\Domain\Drafting\Exceptions\LlmCredentialMissing;
use App\Domain\Drafting\LlmCredentialResolver;
use App\Domain\Drafting\Models\InstanceLlmCredential;
use App\Domain\Drafting\Models\LlmCredential;
use App\Domain\Workspaces\Models\Workspace;
use App\Domain\Workspaces\Role;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../../Support/publishing.php';

itIsolatesWorkspaces(fn () => LlmCredential::factory()->create());

beforeEach(function () {
    $this->workspace = Workspace::factory()->create();

    config(['noongun.drafting.providers.fake' => ['api_key' => null, 'model' => 'fake-model']]);
});

function resolveKey(Workspace $workspace, string $provider = 'fake')
{
    return app(LlmCredentialResolver::class)->resolve($workspace, $provider);
}

describe('a workspace key', function () {
    it('is encrypted at rest', function () {
        $credential = inWorkspace($this->workspace, fn () => LlmCredential::store('fake', 'plain-workspace-llm-key'));

        $row = DB::table('llm_credentials')->where('id', $credential->id)->first();

        expect($row->api_key)->not->toContain('plain-workspace-llm-key')
            ->and(Crypt::decryptString($row->api_key))->toBe('plain-workspace-llm-key')
            ->and($row->scope)->toBe('workspace')
            ->and($row->workspace_id)->toBe($this->workspace->id)
            ->and(inWorkspace($this->workspace, fn () => LlmCredential::findOrFail($credential->id))->api_key)->toBe('plain-workspace-llm-key');
    });

    it('is hidden from arrays and JSON', function () {
        $credential = inWorkspace($this->workspace, fn () => LlmCredential::store('fake', 'plain-workspace-llm-key'));

        expect($credential->toArray())->not->toHaveKey('api_key')
            ->and($credential->toJson())->not->toContain('plain-workspace-llm-key')
            ->and(json_encode(['credential' => $credential]))->not->toContain('api_key')
            ->and($credential->lastFour())->toBe('-key');
    });

    it('is one per provider, and storing again replaces it', function () {
        inWorkspace($this->workspace, function () {
            $first = LlmCredential::store('fake', 'the-first-llm-key');
            $second = LlmCredential::store('fake', 'the-second-llm-key');
            LlmCredential::store('other', 'another-llm-key');

            expect($second->id)->toBe($first->id)
                ->and(LlmCredential::query()->count())->toBe(2)
                ->and($second->fresh()?->api_key)->toBe('the-second-llm-key');

            expect(fn () => LlmCredential::factory()->provider('fake')->create())->toThrow(QueryException::class);
        });
    });

    it('is always of the workspace scope, whatever is passed', function () {
        $credential = inWorkspace($this->workspace, fn () => (new LlmCredential)->forceFill([
            'provider' => 'fake', 'api_key' => 'a-key-for-the-workspace', 'scope' => 'instance',
        ]));

        inWorkspace($this->workspace, fn () => $credential->save());

        expect(DB::table('llm_credentials')->where('id', $credential->id)->value('scope'))->toBe('workspace');
    });
});

describe('an instance key', function () {
    it('belongs to no workspace and is encrypted and hidden too', function () {
        $credential = InstanceLlmCredential::store('fake', 'plain-instance-llm-key');
        InstanceLlmCredential::store('fake', 'plain-instance-llm-key-2');

        $row = DB::table('llm_credentials')->where('id', $credential->id)->first();

        expect($row->workspace_id)->toBeNull()
            ->and($row->scope)->toBe('instance')
            ->and(Crypt::decryptString($row->api_key))->toBe('plain-instance-llm-key-2')
            ->and(InstanceLlmCredential::query()->count())->toBe(1)
            ->and($credential->toJson())->not->toContain('plain-instance-llm-key');
    });

    it('is never seen through the workspace model, from any workspace', function () {
        InstanceLlmCredential::store('fake', 'plain-instance-llm-key');
        inWorkspace($this->workspace, fn () => LlmCredential::store('fake', 'plain-workspace-llm-key'));

        expect(inWorkspace($this->workspace, fn () => LlmCredential::query()->pluck('scope')->all()))->toBe([CredentialScope::Workspace])
            ->and(inWorkspace(Workspace::factory()->create(), fn () => LlmCredential::query()->count()))->toBe(0)
            ->and(LlmCredential::query()->count())->toBe(0)
            ->and(InstanceLlmCredential::query()->pluck('scope')->all())->toBe([CredentialScope::Instance]);
    });

    it('cannot carry a workspace, and a workspace key cannot go without one, because the database refuses both', function () {
        $instance = InstanceLlmCredential::store('fake', 'plain-instance-llm-key');
        $own = inWorkspace($this->workspace, fn () => LlmCredential::store('fake', 'plain-workspace-llm-key'));

        expectRefusedByDatabase(fn () => DB::table('llm_credentials')->where('id', $instance->id)->update(['workspace_id' => $this->workspace->id]));
        expectRefusedByDatabase(fn () => DB::table('llm_credentials')->where('id', $own->id)->update(['workspace_id' => null]));
        expectRefusedByDatabase(fn () => DB::table('llm_credentials')->where('id', $own->id)->update(['scope' => 'instance']));
    });

    it('is one per provider', function () {
        InstanceLlmCredential::store('fake', 'plain-instance-llm-key');

        expect(fn () => DB::table('llm_credentials')->insert(['scope' => 'instance', 'provider' => 'fake', 'api_key' => 'x']))->toThrow(QueryException::class);
    });
});

describe('resolving the key for a workspace', function () {
    it('fails clearly when there is no key anywhere', function () {
        expect(fn () => resolveKey($this->workspace))->toThrow(function (LlmCredentialMissing $missing) {
            expect($missing->provider)->toBe('fake')
                ->and($missing->retryable)->toBeFalse()
                ->and($missing->getMessage())->toBe('No API key is set for Fake. An owner of this workspace can add one under Settings, Drafting.')
                ->and($missing->operatorDetail())->toContain('Provider [fake] has no key')
                ->and($missing->context())->toMatchArray(['workspace_id' => $this->workspace->id]);
        });

        expect(app(LlmCredentialResolver::class)->has($this->workspace, 'fake'))->toBeFalse();
    });

    it('uses the instance key from the environment', function () {
        config(['noongun.drafting.providers.fake.api_key' => 'env-instance-llm-key']);

        $key = resolveKey($this->workspace);

        expect($key->reveal())->toBe('env-instance-llm-key')
            ->and($key->scope)->toBe(CredentialScope::Instance)
            ->and($key->provider)->toBe('fake')
            ->and(app(LlmCredentialResolver::class)->has($this->workspace, 'fake'))->toBeTrue();
    });

    it('puts a stored instance key before the environment', function () {
        config(['noongun.drafting.providers.fake.api_key' => 'env-instance-llm-key']);
        InstanceLlmCredential::store('fake', 'stored-instance-llm-key');

        expect(resolveKey($this->workspace)->reveal())->toBe('stored-instance-llm-key')
            ->and(resolveKey($this->workspace)->scope)->toBe(CredentialScope::Instance);
    });

    it('puts the workspace key before both instance keys', function () {
        config(['noongun.drafting.providers.fake.api_key' => 'env-instance-llm-key']);
        InstanceLlmCredential::store('fake', 'stored-instance-llm-key');
        inWorkspace($this->workspace, fn () => LlmCredential::store('fake', 'own-workspace-llm-key'));

        expect(resolveKey($this->workspace)->reveal())->toBe('own-workspace-llm-key')
            ->and(resolveKey($this->workspace)->scope)->toBe(CredentialScope::Workspace);
    });

    it('never gives one workspace the key of another', function () {
        $other = Workspace::factory()->create();
        inWorkspace($other, fn () => LlmCredential::store('fake', 'their-workspace-llm-key'));

        expect(fn () => resolveKey($this->workspace))->toThrow(LlmCredentialMissing::class)
            ->and(fn () => inWorkspace($other, fn () => resolveKey($this->workspace)))->toThrow(LlmCredentialMissing::class)
            ->and(resolveKey($other)->reveal())->toBe('their-workspace-llm-key');
    });

    it('keeps keys of different providers apart', function () {
        inWorkspace($this->workspace, fn () => LlmCredential::store('other', 'key-for-another-provider'));

        expect(fn () => resolveKey($this->workspace, 'fake'))->toThrow(LlmCredentialMissing::class)
            ->and(resolveKey($this->workspace, 'other')->reveal())->toBe('key-for-another-provider');
    });

    it('works from a job, without a current workspace, and leaves none behind', function () {
        inWorkspace($this->workspace, fn () => LlmCredential::store('fake', 'own-workspace-llm-key'));

        expect(resolveKey($this->workspace)->reveal())->toBe('own-workspace-llm-key')
            ->and(LlmCredential::query()->count())->toBe(0);
    });
});

it('lets only owners manage the keys of a workspace', function () {
    $credential = inWorkspace($this->workspace, fn () => LlmCredential::store('fake', 'own-workspace-llm-key'));
    $owner = userWithRole($this->workspace, Role::Owner);
    $editor = userWithRole($this->workspace, Role::Editor);
    $otherOwner = userWithRole(Workspace::factory()->create(), Role::Owner);

    inWorkspace($this->workspace, function () use ($credential, $owner, $editor, $otherOwner) {
        expect($owner->can('viewAny', LlmCredential::class))->toBeTrue()
            ->and($owner->can('create', LlmCredential::class))->toBeTrue()
            ->and($owner->can('update', $credential))->toBeTrue()
            ->and($owner->can('delete', $credential))->toBeTrue()
            ->and($editor->can('viewAny', LlmCredential::class))->toBeFalse()
            ->and($editor->can('delete', $credential))->toBeFalse()
            ->and($otherOwner->can('create', LlmCredential::class))->toBeFalse()
            ->and($otherOwner->can('update', $credential))->toBeFalse();
    });
});
