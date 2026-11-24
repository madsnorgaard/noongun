<?php

namespace App\Domain\Workspaces\Exceptions;

use RuntimeException;

class MissingWorkspaceContext extends RuntimeException
{
    public function __construct(string $message = 'No current workspace is set. Use CurrentWorkspace::run() in jobs and commands.')
    {
        parent::__construct($message);
    }
}
