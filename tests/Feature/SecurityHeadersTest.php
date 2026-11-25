<?php

use App\Domain\Workspaces\Models\Workspace;
use App\Domain\Workspaces\Role;
use App\Models\User;

require_once __DIR__.'/../Support/members.php';

function assertSecurityHeaders($response): void
{
    $response
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'same-origin')
        ->assertHeader('Content-Security-Policy', "frame-ancestors 'none'");
}

it('sets the security headers on pages, redirects and refusals', function () {
    $owner = workspaceMember(Workspace::factory()->create(), Role::Owner);

    assertSecurityHeaders($this->get('/login')->assertOk());
    assertSecurityHeaders($this->get('/dashboard')->assertRedirect());
    assertSecurityHeaders($this->actingAs($owner)->get('/dashboard')->assertOk());
    assertSecurityHeaders($this->actingAs($owner)->get('/posts/999999')->assertNotFound());
    assertSecurityHeaders($this->actingAs(User::factory()->create())->get('/horizon')->assertForbidden());
});

it('keeps the Horizon dashboard working', function () {
    $owner = workspaceMember(Workspace::factory()->create(), Role::Owner);

    assertSecurityHeaders($this->actingAs($owner)->get('/horizon')->assertOk());
});

it('sends HSTS only over HTTPS', function () {
    User::factory()->create();

    $this->get('http://noongun.test/login')->assertOk()->assertHeaderMissing('Strict-Transport-Security');
    $this->get('https://noongun.test/login')->assertOk()->assertHeader('Strict-Transport-Security', 'max-age=31536000');
});

it('sends HSTS behind a trusted proxy that terminates HTTPS, and not for a forged header', function () {
    User::factory()->create();

    config()->set('trustedproxy.proxies', null);
    $this->withHeaders(['X-Forwarded-Proto' => 'https'])->get('/login')->assertHeaderMissing('Strict-Transport-Security');

    config()->set('trustedproxy.proxies', ['127.0.0.1']);
    $this->withHeaders(['X-Forwarded-Proto' => 'https'])->get('/login')->assertHeader('Strict-Transport-Security', 'max-age=31536000');
});
