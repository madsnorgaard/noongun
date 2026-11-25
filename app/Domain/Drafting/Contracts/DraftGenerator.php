<?php

namespace App\Domain\Drafting\Contracts;

use App\Domain\Drafting\DraftingContext;
use App\Domain\Drafting\DraftRequest;
use App\Domain\Drafting\DraftResult;
use App\Domain\Drafting\Exceptions\DraftGenerationFailed;
use App\Domain\Drafting\Exceptions\InvalidDraftOutput;

/**
 * Turns a request into one draft. This is the only place that talks to an
 * LLM vendor or to Prism: controllers, jobs and UI call generate() and
 * never the vendor directly. Resolve an implementation through
 * DraftGenerators.
 *
 * Generators are stateless and write nothing. They are built through the
 * container without arguments; provider, model, limits, prompt version and
 * key all arrive in the context. The caller records the run and creates the
 * post, see Actions\GenerateDraft.
 *
 * An implementation must:
 *
 * - send the provider only what PromptRenderer::render() returns for the
 *   request and the context's prompt version, plus DraftSchema::definition()
 *   as the output format;
 * - take provider, model, token limit and timeout from the context and name
 *   none of them itself;
 * - build the result with DraftResult::fromArray(), and when that throws
 *   InvalidDraftOutput ask once more, then let the exception out with the
 *   usage of both attempts (InvalidDraftOutput::withUsage());
 * - report every other failure as DraftGenerationFailed, built from the
 *   vendor's error without passing the vendor exception on, with the key
 *   given as a secret to remove.
 *
 * The brief (section 7) sketches generate() as returning one result per
 * channel. Here one result holds the texts for all channels of one post,
 * because link, alternative text and rationale belong to the post. See
 * docs/adr/0011-drafting-contract.md.
 */
interface DraftGenerator
{
    /**
     * @throws InvalidDraftOutput after a second invalid answer
     * @throws DraftGenerationFailed
     */
    public function generate(DraftRequest $request, DraftingContext $context): DraftResult;
}
