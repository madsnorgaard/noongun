<?php

use App\Domain\Drafting\DraftUsage;
use App\Domain\Drafting\Exceptions\DraftGenerationFailed;
use App\Domain\Drafting\Exceptions\DraftingException;

it('carries whether asking again can help, and what the attempt used', function () {
    $usage = new DraftUsage('fake', 'fake-model', 100, 0);
    $retryable = DraftGenerationFailed::retryable('fake', 'Rate limit reached.', $usage, ['status' => 429]);
    $permanent = DraftGenerationFailed::permanent('fake', 'The request was refused.');

    expect($retryable)->toBeInstanceOf(DraftingException::class)
        ->and($retryable->retryable)->toBeTrue()
        ->and($retryable->provider)->toBe('fake')
        ->and($retryable->usage)->toBe($usage)
        ->and($retryable->context())->toMatchArray(['provider' => 'fake', 'retryable' => true, 'status' => 429])
        ->and($retryable->context()['usage'])->toMatchArray(['input_tokens' => 100])
        ->and($permanent->retryable)->toBeFalse()
        ->and($permanent->usage)->toBeNull();
});

it('has no way to carry the vendor exception', function () {
    $parameters = array_map(
        fn (ReflectionParameter $parameter) => $parameter->getName(),
        (new ReflectionMethod(DraftingException::class, '__construct'))->getParameters(),
    );

    expect($parameters)->not->toContain('previous')
        ->and(DraftGenerationFailed::permanent('fake', 'Failed.')->getPrevious())->toBeNull();
});

it('removes the key that was used, wherever it appears', function () {
    $key = 'plain-llm-key-of-no-known-shape';

    $failure = DraftGenerationFailed::permanent(
        'fake',
        "Request failed with header x-custom: {$key}",
        context: ['body' => "echo {$key}", 'nested' => ['again' => $key]],
        secrets: [$key],
    );

    expect($failure->getMessage())->not->toContain($key)
        ->and($failure->getMessage())->toContain(DraftingException::REDACTED)
        ->and(json_encode($failure->context()))->not->toContain($key);
});

it('removes credentials it was not told about', function (string $message, string $secret) {
    $failure = DraftGenerationFailed::permanent('fake', $message);

    expect($failure->getMessage())->not->toContain($secret)
        ->and($failure->getMessage())->toContain(DraftingException::REDACTED);
})->with([
    'key header' => ['POST /v1/messages failed. Headers: x-api-key: abc123def456', 'abc123def456'],
    'key header in JSON' => ['{"x-api-key":"abc123def456","anthropic-version":"2023-06-01"}', 'abc123def456'],
    'key shaped like a provider key' => ['Invalid key sk-test-0123456789abcdefghij given.', 'sk-test-0123456789abcdefghij'],
    'bearer header' => ['Authorization: Bearer AQX.header-value', 'AQX.header-value'],
    'api_key parameter' => ['GET /models?api_key=abc123def456', 'abc123def456'],
]);

it('removes credentials from context, at any depth, and keeps token counts', function () {
    $failure = DraftGenerationFailed::retryable('fake', 'Failed.', context: [
        'api_key' => 'plain-key',
        'status' => 529,
        'input_tokens' => 1200,
        'request' => ['headers' => ['Authorization' => 'Bearer AQX', 'x-api-key' => 'plain-key'], 'max_tokens' => 2000],
        'response' => new stdClass,
    ]);

    expect($failure->context())->toMatchArray([
        'api_key' => DraftingException::REDACTED,
        'status' => 529,
        'input_tokens' => 1200,
        'request' => ['headers' => ['Authorization' => DraftingException::REDACTED, 'x-api-key' => DraftingException::REDACTED], 'max_tokens' => 2000],
        'response' => 'stdClass',
    ]);
});

it('keeps ordinary error text readable', function () {
    $messages = [
        'The provider is overloaded. Try again in a minute.',
        'The answer was cut off after 2000 tokens.',
        'The provider declined to answer this request.',
    ];

    foreach ($messages as $message) {
        expect(DraftGenerationFailed::permanent('fake', $message)->getMessage())->toBe($message);
    }
});
