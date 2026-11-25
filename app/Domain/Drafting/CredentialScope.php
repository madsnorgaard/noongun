<?php

namespace App\Domain\Drafting;

/**
 * Whose LLM key it is: the operator's, shared by the instance, or one a
 * workspace brought itself.
 */
enum CredentialScope: string
{
    case Instance = 'instance';
    case Workspace = 'workspace';
}
