<?php

namespace App\Domain\Channels\Policies;

use App\Domain\Channels\Models\Channel;
use App\Domain\Workspaces\CurrentWorkspace;
use App\Domain\Workspaces\Role;
use App\Models\User;

/**
 * Every member may look at the channels of a workspace. Owners and editors
 * may connect and disconnect them.
 */
class ChannelPolicy
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    public function viewAny(User $user): bool
    {
        return $this->roleInCurrentWorkspace($user)?->canView() ?? false;
    }

    /**
     * Connecting a channel.
     */
    public function create(User $user): bool
    {
        return $this->roleInCurrentWorkspace($user)?->canEditContent() ?? false;
    }

    /**
     * Disconnecting a channel. The row is kept, see Channel::disconnect().
     */
    public function delete(User $user, Channel $channel): bool
    {
        return $channel->workspace?->roleOf($user)?->canEditContent() ?? false;
    }

    private function roleInCurrentWorkspace(User $user): ?Role
    {
        return $this->current->get()?->roleOf($user);
    }
}
