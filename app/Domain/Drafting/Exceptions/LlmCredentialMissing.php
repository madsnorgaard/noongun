<?php

namespace App\Domain\Drafting\Exceptions;

use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Support\Str;

/**
 * No API key is available for the provider: the workspace has none of its
 * own and the instance has none either. Not retryable; a human adds a key.
 */
class LlmCredentialMissing extends DraftingException
{
    public static function for(string $provider, Workspace $workspace): static
    {
        return new static(
            $provider,
            'No API key is set for '.Str::headline($provider).'. An owner of this workspace can add one under Settings, Drafting.',
            false,
            null,
            [
                'workspace_id' => $workspace->id,
                self::OPERATOR_DETAIL => "Provider [{$provider}] has no key: none stored for workspace #{$workspace->id}, none stored for the instance, and none in the environment.",
            ],
        );
    }
}
