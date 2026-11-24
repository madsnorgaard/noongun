<?php

namespace App\Domain\Workspaces\Models;

use App\Domain\Workspaces\Role;
use App\Models\User;
use Database\Factories\MembershipFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * Links a user to a workspace with a role.
 *
 * Memberships carry workspace_id but are deliberately not scoped by
 * WorkspaceScope: they are how the current workspace is resolved, so they
 * must be readable before one is known.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $user_id
 * @property Role $role
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Table('memberships')]
#[Fillable(['workspace_id', 'user_id', 'role'])]
#[UseFactory(MembershipFactory::class)]
class Membership extends Pivot
{
    /** @use HasFactory<MembershipFactory> */
    use HasFactory;

    public $incrementing = true;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => Role::class,
        ];
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
