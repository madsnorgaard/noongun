<?php

namespace App\Domain\Content\Exceptions;

use App\Domain\Content\Models\PostVariant;
use LogicException;

/**
 * A second publish result arrived for a variant that already has an
 * external id. The first result stands.
 */
class VariantAlreadyPublished extends LogicException
{
    public static function for(PostVariant $variant): self
    {
        return new self("Variant #{$variant->getKey()} already has an external id and cannot be marked as published again.");
    }
}
