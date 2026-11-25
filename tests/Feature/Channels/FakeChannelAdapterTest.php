<?php

use App\Domain\Channels\Contracts\PublishResult;
use App\Domain\Channels\Exceptions\PublishFailed;
use App\Domain\Content\Models\PostVariant;
use App\Domain\Workspaces\Models\Workspace;

beforeEach(function () {
    $this->workspace = Workspace::factory()->create();
    $this->fake = fakeChannelAdapter();
    $this->variant = inWorkspace($this->workspace, fn () => PostVariant::factory()->create());
});

it('records publish calls per variant and returns a new external id each time', function () {
    $other = inWorkspace($this->workspace, fn () => PostVariant::factory()->create());

    $first = $this->fake->publish($this->variant);
    $second = $this->fake->publish($this->variant);

    expect($first->externalId)->not->toBe($second->externalId)
        ->and($this->fake->publishCount())->toBe(2)
        ->and($this->fake->publishCount($other))->toBe(0);

    $this->fake->assertPublishedTimes($this->variant, 2)->assertNotPublished($other);
});

it('returns a configured result', function () {
    $this->fake->publishReturns(new PublishResult('123_456', 'https://www.facebook.com/123_456', now()));

    expect($this->fake->publish($this->variant)->permalink)->toBe('https://www.facebook.com/123_456');
});

it('throws retryable failures the given number of times, then succeeds', function () {
    $this->fake->failRetryable(times: 2, retryAfterSeconds: 30);

    foreach ([1, 2] as $attempt) {
        try {
            $this->fake->publish($this->variant);
            $this->fail('Expected a failure.');
        } catch (PublishFailed $failure) {
            expect($failure->retryable)->toBeTrue()->and($failure->retryAfterSeconds)->toBe(30);
        }
    }

    expect($this->fake->publish($this->variant))->toBeInstanceOf(PublishResult::class);
    $this->fake->assertPublishedTimes($this->variant, 3);
});

it('throws failures that must not be retried', function () {
    $this->fake->failPermanently();
    expect(fn () => $this->fake->publish($this->variant))
        ->toThrow(fn (PublishFailed $failure) => expect($failure->retryable)->toBeFalse());

    $this->fake->failReconnectRequired();
    expect(fn () => $this->fake->publish($this->variant))
        ->toThrow(fn (PublishFailed $failure) => expect($failure->requiresReconnect)->toBeTrue());
});

it('can fail every time until told otherwise', function () {
    $this->fake->alwaysFail();

    expect(fn () => $this->fake->publish($this->variant))->toThrow(PublishFailed::class)
        ->and(fn () => $this->fake->publish($this->variant))->toThrow(PublishFailed::class);

    expect($this->fake->publishSucceeds()->publish($this->variant))->toBeInstanceOf(PublishResult::class);
});

it('records deletes, connects and token refreshes', function () {
    $channels = inWorkspace($this->workspace, fn () => $this->fake->connect($this->workspace, 'code'));
    $this->fake->delete($this->variant);
    $this->fake->refreshToken($channels->first());

    expect($channels)->toHaveCount(1)
        ->and($channels->first()->platform)->toBe('facebook')
        ->and($this->fake->authorizationUrl($this->workspace, 'abc'))->toContain('state=abc')
        ->and($this->fake->callCount('connect'))->toBe(1)
        ->and($this->fake->callCount('refreshToken'))->toBe(1);

    $this->fake->assertDeleted($this->variant)->assertNothingPublished();
});
