<?php

use App\Domain\Channels\Exceptions\ChannelException;
use App\Domain\Channels\Exceptions\PublishFailed;

it('carries whether a failure is retryable', function () {
    $retryable = PublishFailed::retryable('facebook', 'Rate limit reached.', 120, ['error_code' => 4]);
    $permanent = PublishFailed::permanent('facebook', 'The message is too long.');
    $reconnect = PublishFailed::reconnectRequired('facebook', 'The session has expired.');

    expect($retryable)->toBeInstanceOf(ChannelException::class)
        ->and($retryable->retryable)->toBeTrue()
        ->and($retryable->retryAfterSeconds)->toBe(120)
        ->and($retryable->requiresReconnect)->toBeFalse()
        ->and($retryable->platform)->toBe('facebook')
        ->and($retryable->context())->toMatchArray(['platform' => 'facebook', 'retryable' => true, 'error_code' => 4])
        ->and($permanent->retryable)->toBeFalse()
        ->and($permanent->requiresReconnect)->toBeFalse()
        ->and($reconnect->retryable)->toBeFalse()
        ->and($reconnect->requiresReconnect)->toBeTrue();
});

it('removes credentials from messages', function (string $message, string $secret) {
    $exception = PublishFailed::permanent('facebook', $message);

    expect($exception->getMessage())->not->toContain($secret)
        ->and($exception->getMessage())->toContain(ChannelException::REDACTED);
})->with([
    'query string token' => ['cURL error 28 for https://graph.facebook.com/v27.0/1/feed?access_token=EAAB-s3cret&message=hi', 'EAAB-s3cret'],
    'long-lived exchange' => ['GET /oauth/access_token?client_secret=abc123def&fb_exchange_token=EAAshort', 'abc123def'],
    'exchange token' => ['GET /oauth/access_token?client_secret=abc123def&fb_exchange_token=EAAshort', 'EAAshort'],
    'authorization code' => ['GET /oauth/access_token?client_id=1&code=AQD-code-value', 'AQD-code-value'],
    'app secret proof' => ['POST /1/feed appsecret_proof=9f8e7d', '9f8e7d'],
    'json body' => ['{"access_token":"EAAB-json","token_type":"bearer"}', 'EAAB-json'],
    'bearer header' => ['Authorization: Bearer AQX.header-value', 'AQX.header-value'],
    'refresh token' => ['refresh_token: AQY-refresh', 'AQY-refresh'],
]);

it('keeps ordinary error text readable', function () {
    $messages = [
        '(#200) Permission error, code: 200, subcode 1234.',
        'Invalid OAuth access token: the session has expired.',
    ];

    foreach ($messages as $message) {
        expect(PublishFailed::permanent('facebook', $message)->getMessage())->toBe($message);
    }
});

it('removes credentials from context, at any depth', function () {
    $exception = PublishFailed::retryable('facebook', 'Failed.', context: [
        'access_token' => 'EAAB-context',
        'status' => 500,
        'code' => 190,
        'request' => [
            'headers' => ['Authorization' => 'Bearer AQX', 'Accept' => 'application/json'],
            'url' => 'https://graph.facebook.com/me?access_token=EAAB-url',
            'client_secret' => 'shh',
        ],
        'response' => new stdClass,
    ]);

    $context = $exception->context();

    expect(json_encode($context))->not->toContain('EAAB-context')
        ->not->toContain('EAAB-url')
        ->not->toContain('AQX')
        ->not->toContain('shh')
        ->and($context['status'])->toBe(500)
        ->and($context['code'])->toBe(190)
        ->and($context['request']['headers']['Accept'])->toBe('application/json')
        ->and($context['response'])->toBe('stdClass');
});

it('has no way to chain a previous exception', function () {
    $constructor = new ReflectionMethod(ChannelException::class, '__construct');
    $names = array_map(fn (ReflectionParameter $parameter) => $parameter->getName(), $constructor->getParameters());

    expect($names)->not->toContain('previous')
        ->and(PublishFailed::permanent('facebook', 'Failed.')->getPrevious())->toBeNull();
});
