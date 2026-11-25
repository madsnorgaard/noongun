<?php

namespace App\Domain\Drafting\Exceptions;

/**
 * Thrown by DraftGenerator::generate() when the provider could not be
 * reached, refused the request or declined to answer. $retryable tells the
 * caller whether asking again later can help.
 */
class DraftGenerationFailed extends DraftingException {}
