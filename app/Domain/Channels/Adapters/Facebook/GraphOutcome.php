<?php

namespace App\Domain\Channels\Adapters\Facebook;

/**
 * What became of one read inside a batch request to the Graph API.
 */
enum GraphOutcome
{
    case Answered;

    /** The token is expired, revoked or otherwise not accepted. */
    case TokenRefused;

    /** The token is accepted but lacks a permission for this read. */
    case PermissionMissing;

    case RateLimited;

    /** A problem on Facebook's side that says nothing about the object. */
    case Temporary;

    /** The object does not exist, or not for this token. */
    case NotFound;

    /** Any other refusal, such as a parameter Facebook does not accept. */
    case Refused;
}
