<?php

namespace App\Domain\Drafting\Exceptions;

use App\Domain\Drafting\DraftingConfig;
use App\Domain\Workspaces\Models\Workspace;

/**
 * No API key is available for the provider: the workspace has none of its
 * own and the instance has none either. Not retryable; a human adds a key.
 */
class LlmCredentialMissing extends DraftingException
{
    /**
     * The workspace has a key of its own, but it cannot be decrypted any
     * more. The stored value is not part of the exception.
     */
    public static function unreadable(string $provider, Workspace $workspace): static
    {
        return new static(
            $provider,
            'The API key stored for this workspace can no longer be read. An owner of this workspace can enter it again under Settings, Drafting.',
            false,
            null,
            [
                'workspace_id' => $workspace->id,
                self::OPERATOR_DETAIL => "The key stored for provider [{$provider}] and workspace #{$workspace->id} cannot be decrypted. This happens when APP_KEY was changed or the database was restored next to another APP_KEY.",
            ],
        );
    }

    public static function for(string $provider, Workspace $workspace, bool $storedInstanceKeyUnreadable = false): static
    {
        return new static(
            $provider,
            'No API key is set for '.DraftingConfig::label($provider).'. An owner of this workspace can add one under Settings, Drafting.',
            false,
            null,
            [
                'workspace_id' => $workspace->id,
                self::OPERATOR_DETAIL => "Provider [{$provider}] has no key: none stored for workspace #{$workspace->id}, ".($storedInstanceKeyUnreadable ? 'the one stored for the instance cannot be decrypted (APP_KEY was changed)' : 'none stored for the instance').', and none in the environment.',
            ],
        );
    }
}
