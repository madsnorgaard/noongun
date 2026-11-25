<?php

namespace App\Domain\Drafting\Models;

use App\Domain\Drafting\CredentialScope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * The draft runs of every workspace that the key of the instance pays for,
 * for counting only. The operator's key is one purse shared by all
 * workspaces, so what was drawn from it cannot be known from inside one
 * workspace: this is the one read of draft_runs that is not scoped to a
 * workspace, in the way InstanceLlmCredential is for the key itself.
 *
 * It gives numbers, never rows to a tenant: only DraftBudget uses it, and
 * only with count(). It cannot be saved or deleted. Runs are written
 * through DraftRun, inside their workspace.
 */
#[Table('draft_runs')]
class InstanceKeyRun extends Model
{
    protected static function booted(): void
    {
        static::addGlobalScope('instance_key', function (Builder $query): void {
            $query->where('credential_scope', CredentialScope::Instance);
        });

        static::saving(fn () => throw new LogicException('InstanceKeyRun is for counting. Write draft runs through DraftRun.'));
        static::deleting(fn () => throw new LogicException('InstanceKeyRun is for counting. Write draft runs through DraftRun.'));
    }
}
