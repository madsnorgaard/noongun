<?php

namespace App\Domain\Drafting\Models;

use App\Domain\Drafting\CredentialScope;
use App\Domain\Drafting\Policies\LlmCredentialPolicy;
use App\Domain\Workspaces\Concerns\BelongsToWorkspace;
use Carbon\CarbonImmutable;
use Database\Factories\LlmCredentialFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use SensitiveParameter;

/**
 * An API key a workspace brought for one LLM provider (bring your own key).
 * One per workspace and provider.
 *
 * The key is encrypted at rest and hidden from arrays and JSON. Never put it
 * in a log line, an exception or a fixture, and never send it to a page:
 * show whether a key is set and lastFour() at most.
 *
 * This model only ever sees rows of the workspace scope. The operator's
 * instance keys live in the same table without a workspace and are read
 * through InstanceLlmCredential. LlmCredentialResolver decides which key a
 * workspace uses.
 *
 * @property int $id
 * @property int $workspace_id
 * @property CredentialScope $scope
 * @property string $provider
 * @property string $api_key
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['provider', 'api_key'])]
#[Hidden(['api_key'])]
#[UseFactory(LlmCredentialFactory::class)]
#[UsePolicy(LlmCredentialPolicy::class)]
class LlmCredential extends Model
{
    use BelongsToWorkspace;

    /** @use HasFactory<LlmCredentialFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (LlmCredential $credential): void {
            $credential->setAttribute('scope', CredentialScope::Workspace);
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
     * Sets or replaces the current workspace's key for a provider.
     */
    public static function store(string $provider, #[SensitiveParameter] string $apiKey): self
    {
        return static::query()->updateOrCreate(['provider' => $provider], ['api_key' => $apiKey]);
    }

    /**
     * The end of the key, to let a user recognise which one is stored.
     */
    public function lastFour(): string
    {
        return mb_strlen($this->api_key) > 8 ? mb_substr($this->api_key, -4) : '';
    }
}
