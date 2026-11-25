<?php

namespace App\Domain\Drafting\Models;

use App\Domain\Drafting\CredentialScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use SensitiveParameter;

/**
 * An API key of the instance for one LLM provider, set by the operator and
 * used by every workspace that has no key of its own. One per provider.
 *
 * It is the one kind of row outside `users` and `workspaces` that belongs
 * to no workspace: workspace_id is null and the scope column says
 * "instance". It is not tenant data and is never listed for a tenant; the
 * database refuses an instance row with a workspace and a workspace row
 * without one. Only LlmCredentialResolver and operator tooling read it. A
 * key stored here goes before the key in the environment.
 *
 * @property int $id
 * @property CredentialScope $scope
 * @property string $provider
 * @property string $api_key
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Table('llm_credentials')]
#[Fillable(['provider', 'api_key'])]
#[Hidden(['api_key'])]
class InstanceLlmCredential extends Model
{
    protected static function booted(): void
    {
        static::addGlobalScope('instance', function (Builder $query): void {
            $query->where('scope', CredentialScope::Instance)->whereNull('workspace_id');
        });

        static::saving(function (InstanceLlmCredential $credential): void {
            $credential->setAttribute('scope', CredentialScope::Instance);
            $credential->setAttribute('workspace_id', null);
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope' => CredentialScope::class,
            'api_key' => 'encrypted',
        ];
    }

    /**
     * Sets or replaces the instance key for a provider.
     */
    public static function store(string $provider, #[SensitiveParameter] string $apiKey): self
    {
        return static::query()->updateOrCreate(['provider' => $provider], ['api_key' => $apiKey]);
    }
}
