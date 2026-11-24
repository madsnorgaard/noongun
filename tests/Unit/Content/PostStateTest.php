<?php

use App\Domain\Content\PostState;

/** @return array<string, list<PostState>> */
function legalPostTransitions(): array
{
    return [
        'draft' => [PostState::Approved, PostState::Rejected],
        'approved' => [PostState::Scheduled, PostState::Draft],
        'scheduled' => [PostState::Publishing, PostState::Approved, PostState::Draft],
        'publishing' => [PostState::Published, PostState::Failed],
        'published' => [],
        'failed' => [PostState::Scheduled, PostState::Draft],
        'rejected' => [PostState::Draft],
    ];
}

it('has exactly the states of the brief', function () {
    expect(array_map(fn (PostState $state) => $state->value, PostState::cases()))
        ->toBe(['draft', 'approved', 'scheduled', 'publishing', 'published', 'failed', 'rejected']);
});

it('allows only the documented transitions', function (PostState $from, PostState $to) {
    $legal = in_array($to, legalPostTransitions()[$from->value], true);

    expect($from->canTransitionTo($to))->toBe($legal);
})->with(function () {
    foreach (PostState::cases() as $from) {
        foreach (PostState::cases() as $to) {
            yield "{$from->value} to {$to->value}" => [$from, $to];
        }
    }
});

it('reaches approved from draft and from nowhere else, except by taking a post out of the queue', function () {
    $sources = array_values(array_filter(
        PostState::cases(),
        fn (PostState $from) => $from->canTransitionTo(PostState::Approved),
    ));

    expect($sources)->toBe([PostState::Draft, PostState::Scheduled]);
});

it('reaches publishing only from scheduled', function () {
    $sources = array_values(array_filter(
        PostState::cases(),
        fn (PostState $from) => $from->canTransitionTo(PostState::Publishing),
    ));

    expect($sources)->toBe([PostState::Scheduled]);
});

it('can only be edited as a draft', function (PostState $state) {
    expect($state->isEditable())->toBe($state === PostState::Draft);
})->with(PostState::cases());

it('requires an approval in every state except draft and rejected', function (PostState $state) {
    expect($state->requiresApproval())->toBe(! in_array($state, [PostState::Draft, PostState::Rejected], true));
})->with(PostState::cases());

it('treats published as the only final state', function (PostState $state) {
    expect($state->isFinal())->toBe($state === PostState::Published);
})->with(PostState::cases());
