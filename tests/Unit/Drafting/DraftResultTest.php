<?php

use App\Domain\Drafting\DraftResult;
use App\Domain\Drafting\DraftSchema;
use App\Domain\Drafting\DraftUsage;
use App\Domain\Drafting\Exceptions\InvalidDraftOutput;
use Tests\Fakes\FakeDraftGenerator;

function validOutput(int $channels = 2): array
{
    return FakeDraftGenerator::outputFor(plainDraftRequest($channels));
}

describe('the schema', function () {
    it('is one closed object that lists every property as required', function () {
        $schema = DraftSchema::definition();

        expect($schema['type'])->toBe('object')
            ->and($schema['additionalProperties'])->toBeFalse()
            ->and($schema['required'])->toBe(array_keys($schema['properties']))
            ->and($schema['properties']['variants']['items']['additionalProperties'])->toBeFalse()
            ->and($schema['properties']['variants']['items']['required'])->toBe(['channel_id', 'body']);
    });

    it('uses only what providers enforce: no length or number limits', function () {
        expect(json_encode(DraftSchema::definition()))->not->toMatch('/"(minLength|maxLength|minimum|maximum|minItems|maxItems|pattern|multipleOf)"/');
    });

    it('accepts output that matches', function () {
        expect(DraftSchema::problems(validOutput()))->toBe([])
            ->and(DraftSchema::problems([...validOutput(), 'link' => 'https://example.com', 'alt_text' => 'A cup.']))->toBe([]);
    });

    it('names what is wrong without quoting it', function (mixed $output, string $problem) {
        $problems = DraftSchema::problems($output);

        expect($problems)->toContain($problem)
            ->and(implode(' ', $problems))->not->toContain('SECRET-OUTPUT');
    })->with([
        'not an object' => ['SECRET-OUTPUT', 'The draft must be an object.'],
        'a list' => [['SECRET-OUTPUT'], 'The draft must be an object.'],
        'nothing' => [[], 'The draft has no [variants].'],
        'a missing field' => [array_diff_key(validOutput(), ['rationale' => 0]), 'The draft has no [rationale].'],
        'an extra field' => [[...validOutput(), 'hashtags' => 'SECRET-OUTPUT'], 'The draft has a field that is not part of the format.'],
        'variants as text' => [[...validOutput(), 'variants' => 'SECRET-OUTPUT'], 'The draft [variants] must be a list.'],
        'a variant without a body' => [[...validOutput(), 'variants' => [['channel_id' => 1]]], 'The draft [variants] item 1 has no [body].'],
        'a channel id as text' => [[...validOutput(), 'variants' => [['channel_id' => '1', 'body' => 'SECRET-OUTPUT']]], 'The draft [variants] item 1 [channel_id] must be a whole number.'],
        'a link that is a number' => [[...validOutput(), 'link' => 42], 'The draft [link] has a value of the wrong type.'],
        'a rationale that is null' => [[...validOutput(), 'rationale' => null], 'The draft [rationale] must be a text.'],
    ]);
});

describe('the result', function () {
    it('is built from valid output, one text per requested channel in the order asked', function () {
        $request = plainDraftRequest(2);
        $usage = new DraftUsage('fake', 'fake-model', 1200, 300);

        $result = DraftResult::fromArray([
            'variants' => [
                ['channel_id' => 2, 'body' => "  For the second channel.\n"],
                ['channel_id' => 1, 'body' => 'For the first channel.'],
            ],
            'link' => ' https://example.com/roast ',
            'alt_text' => '',
            'rationale' => ' It fits. ',
        ], $request, $usage);

        expect($result->bodies)->toBe([1 => 'For the first channel.', 2 => 'For the second channel.'])
            ->and($result->bodyFor(2))->toBe('For the second channel.')
            ->and($result->bodyFor(3))->toBeNull()
            ->and($result->link)->toBe('https://example.com/roast')
            ->and($result->altText)->toBeNull()
            ->and($result->rationale)->toBe('It fits.')
            ->and($result->usage)->toBe($usage)
            ->and($result->variants())->toBe([
                ['channel_id' => 1, 'body' => 'For the first channel.', 'link' => 'https://example.com/roast'],
                ['channel_id' => 2, 'body' => 'For the second channel.', 'link' => 'https://example.com/roast'],
            ]);
    });

    it('can carry the usage of two attempts', function () {
        $result = draftResultFor(plainDraftRequest());
        $both = $result->usage->plus($result->usage);

        expect($result->withUsage($both)->usage->attempts)->toBe(2)
            ->and($result->withUsage($both)->bodies)->toBe($result->bodies);
    });

    it('rejects output that does not match the schema, with a typed exception that carries the usage', function () {
        $usage = new DraftUsage('fake', 'fake-model', 1200, 300);

        try {
            DraftResult::fromArray(['variants' => 'none'], plainDraftRequest(), $usage);
            $this->fail('Invalid output was accepted.');
        } catch (InvalidDraftOutput $failure) {
            expect($failure->usage)->toBe($usage)
                ->and($failure->provider)->toBe('fake')
                ->and($failure->retryable)->toBeFalse()
                ->and($failure->problems())->toContain('The draft [variants] must be a list.')
                ->and($failure->getMessage())->toStartWith('The generated draft could not be used:');
        }
    });

    it('rejects output that matches the schema but not the request', function (array $output, string $problem) {
        $request = plainDraftRequest(2, maxBodyLength: 40);

        expect(fn () => DraftResult::fromArray($output, $request, new DraftUsage('fake', 'fake-model', 1, 1)))
            ->toThrow(function (InvalidDraftOutput $failure) use ($problem) {
                expect($failure->problems())->toContain($problem);
            });
    })->with([
        'a channel is missing' => [[...validOutput(), 'variants' => [['channel_id' => 1, 'body' => 'Text.']]], 'There is no text for channel 2.'],
        'a channel that was not asked for' => [[...validOutput(), 'variants' => [...validOutput()['variants'], ['channel_id' => 9, 'body' => 'Text.']]], 'There is a text for a channel that was not asked for.'],
        'a channel twice' => [[...validOutput(), 'variants' => [...validOutput()['variants'], ['channel_id' => 1, 'body' => 'Again.']]], 'There is more than one text for channel 1.'],
        'an empty text' => [[...validOutput(), 'variants' => [['channel_id' => 1, 'body' => "  \n"], ['channel_id' => 2, 'body' => 'Text.']]], 'The text for channel 1 is empty.'],
        'a text that is too long' => [[...validOutput(), 'variants' => [['channel_id' => 1, 'body' => str_repeat('a', 41)], ['channel_id' => 2, 'body' => 'Text.']]], 'The text for channel 1 is 41 characters long. The limit is 40.'],
        'a link that is not a web address' => [[...validOutput(), 'link' => 'javascript:alert(1)'], 'The link is not an http or https address.'],
        'an empty rationale' => [[...validOutput(), 'rationale' => '  '], 'The rationale is empty.'],
    ]);

    it('counts characters, not bytes, against the limit', function () {
        $request = plainDraftRequest(1, maxBodyLength: 5);

        expect(draftResultFor($request, ['variants' => [['channel_id' => 1, 'body' => 'æøåæø']]])->bodyFor(1))->toBe('æøåæø');
    });

    it('lets a generator report the usage of both attempts on the failure', function () {
        $first = InvalidDraftOutput::because('fake', ['There is no text for channel 2.'], new DraftUsage('fake', 'fake-model', 10, 5));
        $both = $first->withUsage(new DraftUsage('fake', 'fake-model', 20, 10, 2));

        expect($both)->toBeInstanceOf(InvalidDraftOutput::class)
            ->and($both->usage?->attempts)->toBe(2)
            ->and($both->problems())->toBe(['There is no text for channel 2.']);
    });
});
