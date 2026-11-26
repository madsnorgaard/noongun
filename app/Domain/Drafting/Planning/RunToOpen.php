<?php

namespace App\Domain\Drafting\Planning;

use App\Domain\Content\Models\Idea;
use App\Domain\Content\Models\Pillar;

/**
 * One draft run that a plan asks for: what goes on the run as its note, and
 * what it is drafted about. StartDraftRuns opens it.
 */
final readonly class RunToOpen
{
    /**
     * @param  array<string, mixed>  $note  what DraftRun::start() stores as the request; never a prompt or a key
     */
    public function __construct(
        public array $note,
        public ?Pillar $pillar = null,
        public ?Idea $idea = null,
    ) {}
}
