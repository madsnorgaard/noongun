<?php

use App\Support\TrustedHosts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    config()->set('app.url', 'https://noongun.example.ts.net');
    Route::get('/_test/host', fn () => ['url' => url('/next')]);
});

afterEach(function () {
    Request::setTrustedHosts([]);
});

it('trusts the host of APP_URL and the loopback names, and nothing else', function (string $host, bool $trusted) {
    $matches = collect(TrustedHosts::patterns())
        ->contains(fn (string $pattern): bool => preg_match('{'.$pattern.'}i', $host) === 1);

    expect($matches)->toBe($trusted);
})->with([
    ['noongun.example.ts.net', true],
    ['NOONGUN.example.ts.net', true],
    ['localhost', true],
    ['127.0.0.1', true],
    ['[::1]', true],
    ['evil.example', false],
    ['sub.noongun.example.ts.net', false],
    ['noongun.example.ts.net.evil.example', false],
    ['noongunXexample.ts.net', false],
    ['127.0.0.1.evil.example', false],
]);

it('trusts only the loopback names when APP_URL has no host', function () {
    config()->set('app.url', '');

    expect(TrustedHosts::patterns())->toBe(['^localhost$', '^127\.0\.0\.1$', '^\[::1\]$']);
});

describe('outside the local environment', function () {
    beforeEach(function () {
        $this->app['env'] = 'production';
    });

    it('answers for the host of APP_URL', function () {
        $this->getJson('https://noongun.example.ts.net/_test/host')
            ->assertOk()
            ->assertJson(['url' => 'https://noongun.example.ts.net/next']);
    });

    it('refuses a host that is not the host of APP_URL', function () {
        $this->getJson('https://evil.example/_test/host')->assertStatus(400);
        $this->getJson('https://evil.example/up')->assertStatus(400);
    });

    it('keeps answering the container health check on the loopback address', function () {
        $this->getJson('http://127.0.0.1:8080/up')->assertOk()->assertJson(['status' => 'up']);
    });
});

it('does not check the host in the local environment', function () {
    $this->app['env'] = 'local';

    $this->getJson('http://anything.example/_test/host')->assertOk();
});
