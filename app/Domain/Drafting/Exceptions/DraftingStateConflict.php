<?php

namespace App\Domain\Drafting\Exceptions;

use App\Domain\Drafting\Models\Brief;
use App\Domain\Drafting\Models\DraftRun;
use LogicException;

/**
 * Something tried to change a record of drafting that is settled: a draft
 * run that is not running any more, or a saved version of the brief.
 */
class DraftingStateConflict extends LogicException
{
    public static function notRunning(DraftRun $run): self
    {
        return new self("Draft run #{$run->getKey()} is {$run->status->value}, not running. Its result was not recorded and no post was created.");
    }

    public static function writtenDirectly(DraftRun $run, string $attribute): self
    {
        return new self("The {$attribute} of draft run #{$run->getKey()} cannot be written directly. Use markRunning(), markSucceeded() or markFailed().");
    }

    public static function briefVersionIsFinal(Brief $brief): self
    {
        return new self("Version {$brief->version} of the brief cannot be changed. Save a new version with Brief::saveNewVersion().");
    }
}
