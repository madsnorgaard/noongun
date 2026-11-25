<?php

namespace App\Domain\Drafting\Exceptions;

use App\Domain\Workspaces\Models\Workspace;

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
            "No API key is set for [{$provider}]. Add a key for this workspace, or ask the operator of this instance to set one.",
            false,
            null,
            ['workspace_id' => $workspace->id],
        );
    }
}
