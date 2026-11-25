<?php

namespace App\Domain\Drafting\Exceptions;

use App\Domain\Drafting\DraftingContext;
use App\Domain\Drafting\Models\DraftRun;

/**
 * A draft run was opened for one provider, model, prompt version and payer,
 * and what resolves for the workspace now is different: a key was added or
 * removed, or the operator changed the model. The run records what was
 * asked for and who pays, and the person saw a cost for exactly that, so it
 * is not run with anything else. Thrown before any provider is called.
 */
class DraftingContextChanged extends DraftingException
{
    /**
     * The exception for a run whose stored context differs from the one
     * given, or null when they are the same.
     */
    public static function between(DraftRun $run, DraftingContext $now): ?static
    {
        $changed = array_keys(array_filter([
            'the provider' => $run->provider !== $now->provider,
            'the model' => $run->model !== $now->model,
            'the prompt version' => $run->prompt_version !== $now->promptVersion,
            'the API key that pays for it' => $run->credential_scope !== $now->key->scope,
        ]));

        if ($changed === []) {
            return null;
        }

        return new static(
            $now->provider,
            'The drafting settings changed after this draft was asked for ('.implode(', ', $changed).'). It was not drafted and nothing was spent. Propose the week again to draft it with the settings as they are now.',
            false,
            null,
            ['draft_run_id' => $run->id, 'changed' => $changed],
        );
    }
}
