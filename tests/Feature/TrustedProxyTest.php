<?php

use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::get('/_test/scheme', fn () => ['secure' => request()->isSecure(), 'url' => url('/next')]);
});

it('ignores forwarded headers when no proxy is trusted', function () {
    config()->set('trustedproxy.proxies', null);

    $this->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'noongun.example.ts.net'])
        ->getJson('/_test/scheme')
        ->assertOk()
        ->assertJson(['secure' => false])
        ->assertJsonMissing(['url' => 'https://noongun.example.ts.net/next']);
});

it('builds https links behind a trusted proxy, with the host the proxy passed on', function () {
    config()->set('trustedproxy.proxies', ['127.0.0.1']);

    $this->withHeaders(['X-Forwarded-Proto' => 'https'])
        ->getJson('http://noongun.example.ts.net/_test/scheme')
        ->assertOk()
        ->assertJson(['secure' => true, 'url' => 'https://noongun.example.ts.net/next']);
});

it('ignores a forwarded host and port, even from a trusted proxy', function () {
    config()->set('trustedproxy.proxies', ['127.0.0.1']);

    $this->withHeaders([
        'X-Forwarded-Proto' => 'https',
        'X-Forwarded-Host' => 'evil.example',
        'X-Forwarded-Port' => '8443',
        'X-Forwarded-Prefix' => '/evil',
    ])
        ->getJson('http://noongun.example.ts.net/_test/scheme')
        ->assertOk()
        ->assertJson(['secure' => true, 'url' => 'https://noongun.example.ts.net/next']);
});

it('ignores forwarded headers from an address that is not a trusted proxy', function () {
    config()->set('trustedproxy.proxies', ['10.9.8.7']);

    $this->withHeaders(['X-Forwarded-Proto' => 'https'])
        ->getJson('/_test/scheme')
        ->assertOk()
        ->assertJson(['secure' => false]);
});

it('reads the trusted proxies from the environment', function (string $value, mixed $expected) {
    putenv("NOONGUN_TRUSTED_PROXIES={$value}");
    $_ENV['NOONGUN_TRUSTED_PROXIES'] = $_SERVER['NOONGUN_TRUSTED_PROXIES'] = $value;

    try {
        expect((require config_path('trustedproxy.php'))['proxies'])->toBe($expected);
    } finally {
        putenv('NOONGUN_TRUSTED_PROXIES');
        unset($_ENV['NOONGUN_TRUSTED_PROXIES'], $_SERVER['NOONGUN_TRUSTED_PROXIES']);
    }
})->with([
    'empty' => ['', null],
    'any' => ['*', '*'],
    'list' => ['172.16.0.0/12, 127.0.0.1', ['172.16.0.0/12', '127.0.0.1']],
]);
