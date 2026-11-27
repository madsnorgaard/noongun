<?php

namespace App\Http\Middleware;

use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Symfony\Component\HttpFoundation\Response;

/**
 * Starts the session so that the request knows who is signed in, and leaves
 * it exactly as it was found. For requests that are not a visit to a page:
 * the images a page shows.
 *
 * The ordinary session middleware notes every GET as "the previous page",
 * so that a form can send the browser back to where it came from, writes
 * the session at the end of every request and sends its cookie again. A
 * page with twenty thumbnails would then make twenty writes, and "back"
 * could lead to an image. Here nothing is noted, nothing is written and no
 * cookie is sent.
 */
class ReadSessionOnly extends StartSession
{
    protected function storeCurrentUrl(Request $request, $session): void {}

    protected function saveSession($request): void {}

    protected function addCookieToResponse(Response $response, Session $session): void {}

    protected function collectGarbage(Session $session): void {}
}
