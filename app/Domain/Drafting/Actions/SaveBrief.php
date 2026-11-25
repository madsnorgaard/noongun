<?php

namespace App\Domain\Drafting\Actions;

use App\Domain\Drafting\Exceptions\DraftingNotAllowed;
use App\Domain\Drafting\Models\Brief;
use App\Domain\Drafting\VoiceRules;
use App\Domain\Workspaces\CurrentWorkspace;
use App\Models\User;

/**
 * A human saves the brief of the current workspace. Every save is a new
 * version; nothing is overwritten.
 */
final class SaveBrief
{
    public function __construct(private readonly CurrentWorkspace $currentWorkspace) {}

    /**
     * @param  array<array-key, mixed>  $callsToAction  entries of ['text' => ..., 'url' => ...]
     * @param  bool  $onlyIfChanged  return the current version, and add none, when the save is the same as it
     *
     * @throws DraftingNotAllowed the actor is not an owner or editor of the workspace
     */
    public function handle(User $actor, string $title, string $body, VoiceRules $voiceRules, array $callsToAction = [], bool $onlyIfChanged = false): Brief
    {
        $workspace = $this->currentWorkspace->getOrFail();

        if (! $actor->exists || ! ($workspace->roleOf($actor)?->canEditContent() ?? false)) {
            throw DraftingNotAllowed::for($actor, $workspace);
        }

        return Brief::saveNewVersion($title, $body, $voiceRules, $callsToAction, $actor, $onlyIfChanged);
    }
}
