<?php

namespace App\Domain\Channels\Contracts;

/**
 * For an adapter whose platform can leave out an account without saying so.
 * The connect pages show the hint after every connect, because the adapter
 * cannot tell whether it happened.
 */
interface ExplainsMissingAccounts
{
    /**
     * One or two sentences for the user: why an account they granted may not
     * be listed, and where to read what to do about it.
     */
    public function missingAccountsHint(): string;
}
