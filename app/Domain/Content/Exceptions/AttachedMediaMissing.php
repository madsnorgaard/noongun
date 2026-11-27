<?php

namespace App\Domain\Content\Exceptions;

use LogicException;

/**
 * A draft names an image that is not in the workspace, or no longer exists:
 * an image nobody used was removed by the clean-up while the editor was open.
 */
class AttachedMediaMissing extends LogicException
{
    /** @var list<int> the ids that were named and not found */
    public array $ids = [];

    /**
     * @param  list<int>  $ids
     */
    public static function for(array $ids): self
    {
        $missing = new self('These images do not exist in the current workspace: '.implode(', ', $ids).'.');
        $missing->ids = $ids;

        return $missing;
    }
}
