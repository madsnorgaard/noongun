<?php

use App\Domain\Channels\Contracts\ChannelCapabilities;
use App\Domain\Drafting\DraftRequest;
use App\Domain\Drafting\DraftResult;
use App\Domain\Drafting\DraftSchema;
use App\Domain\Drafting\DraftUsage;
use App\Domain\Drafting\Exceptions\InvalidDraftOutput;
use App\Domain\Drafting\TargetChannel;
use App\Domain\Drafting\VoiceRules;
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
        $request = plainDraftRequest(2, sourceUrl: 'https://example.com/roast');
        $usage = new DraftUsage('fake', 'fake-model', 1200, 300);

        $result = DraftResult::fromArray([
            'variants' => [
                ['channel_id' => 2, 'body' => "  For the second channel.\n"],
                ['channel_id' => 1, 'body' => 'For the first channel.'],
            ],
            'link' => ' https://example.com/roast ',
            'link_title' => null,
            'link_description' => null,
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
                ['channel_id' => 1, 'body' => 'For the first channel.', 'link' => 'https://example.com/roast', 'link_title' => null, 'link_description' => null],
                ['channel_id' => 2, 'body' => 'For the second channel.', 'link' => 'https://example.com/roast', 'link_title' => null, 'link_description' => null],
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
        'a channel is missing' => [[...validOutput(), 'variants' => [['channel_id' => 1, 'body' => 'Text.']]], 'There is no text for Facebook.'],
        'a channel that was not asked for' => [[...validOutput(), 'variants' => [...validOutput()['variants'], ['channel_id' => 9, 'body' => 'Text.']]], 'There is a text for a channel that was not asked for.'],
        'a channel twice' => [[...validOutput(), 'variants' => [...validOutput()['variants'], ['channel_id' => 1, 'body' => 'Again.']]], 'There is more than one text for Facebook.'],
        'an empty text' => [[...validOutput(), 'variants' => [['channel_id' => 1, 'body' => "  \n"], ['channel_id' => 2, 'body' => 'Text.']]], 'The text for Facebook is empty.'],
        'a text that is too long' => [[...validOutput(), 'variants' => [['channel_id' => 1, 'body' => str_repeat('a', 41)], ['channel_id' => 2, 'body' => 'Text.']]], 'The text for Facebook is 41 characters long. The limit is 40.'],
        'an empty rationale' => [[...validOutput(), 'rationale' => '  '], 'The rationale is empty.'],
    ]);

    it('keeps a link that the author gave, in the author\'s spelling', function (string $suggested, string $kept) {
        $request = plainDraftRequest(
            sourceUrl: 'https://Allowed.example/Roast?batch=7#notes',
            callsToAction: [
                ['text' => 'Order beans', 'url' => 'https://shop.allowed.example/order'],
                ['text' => 'Visit us', 'url' => 'https://allowed.example'],
                ['text' => 'Call us'],
            ],
        );

        $result = draftResultFor($request, ['link' => $suggested]);

        expect($result->link)->toBe($kept)
            ->and($result->linkDropped)->toBeFalse()
            ->and($result->variants()[0]['link'])->toBe($kept);
    })->with([
        'the source URL' => ['https://Allowed.example/Roast?batch=7#notes', 'https://Allowed.example/Roast?batch=7#notes'],
        'the source URL with scheme and host in another case' => ['HTTPS://ALLOWED.EXAMPLE/Roast?batch=7#notes', 'https://Allowed.example/Roast?batch=7#notes'],
        'the source URL with spaces around it' => ["  https://allowed.example/Roast?batch=7#notes\n", 'https://Allowed.example/Roast?batch=7#notes'],
        'a call to action' => ['https://shop.allowed.example/order', 'https://shop.allowed.example/order'],
        'a call to action with the slash of an empty path' => ['https://allowed.example/', 'https://allowed.example'],
    ]);

    it('drops a link that the author did not give, without failing the draft', function (string $suggested) {
        $request = plainDraftRequest(
            sourceUrl: 'https://allowed.example/roast',
            callsToAction: [['text' => 'Order beans', 'url' => 'https://shop.allowed.example/order']],
        );

        $result = draftResultFor($request, ['link' => $suggested]);

        expect($result->link)->toBeNull()
            ->and($result->linkDropped)->toBeTrue()
            ->and($result->withUsage($result->usage)->linkDropped)->toBeTrue()
            ->and($result->variants()[0]['link'])->toBeNull()
            ->and($result->bodyFor(1))->not->toBeNull();
    })->with([
        'an invented address' => ['https://evil.example/landing'],
        'a lookalike host' => ['https://allowed.example.evil.test/roast'],
        'a lookalike with a hyphen' => ['https://allowed-example.test/roast'],
        'the allowed host as user name of another host' => ['https://allowed.example@evil.example/'],
        'an at sign in the path' => ['https://allowed.example/roast@evil.example/roast'],
        'another path on the allowed host' => ['https://allowed.example/other'],
        'the path in another case' => ['https://allowed.example/Roast'],
        'an added query' => ['https://allowed.example/roast?next=https://evil.example'],
        'an added fragment' => ['https://allowed.example/roast#x'],
        'another port' => ['https://allowed.example:8443/roast'],
        'http instead of https' => ['http://allowed.example/roast'],
        'a subdomain' => ['https://www.allowed.example/roast'],
        'a script' => ['javascript:alert(1)'],
        'no scheme' => ['//allowed.example/roast'],
        'a path only' => ['/roast'],
        'a line break after the allowed address' => ["https://allowed.example/roast\r\nX-Injected: 1"],
    ]);

    it('has no link and drops nothing when the model suggests none', function (?string $suggested) {
        $result = draftResultFor(plainDraftRequest(sourceUrl: 'https://allowed.example/roast'), ['link' => $suggested]);

        expect($result->link)->toBeNull()->and($result->linkDropped)->toBeFalse();
    })->with(['null' => [null], 'empty' => ['  ']]);

    it('never keeps a link when the author gave none, or gave one that is not a web address', function (?string $sourceUrl) {
        $result = draftResultFor(plainDraftRequest(sourceUrl: $sourceUrl), ['link' => $sourceUrl ?? 'https://evil.example/']);

        expect($result->link)->toBeNull()->and($result->linkDropped)->toBeTrue();
    })->with(['none' => [null], 'a script' => ['javascript:alert(1)'], 'a file' => ['file:///etc/passwd']]);

    it('names a channel by its platform, never by its id', function () {
        $request = plainDraftRequest(1, maxBodyLength: 40, platform: 'linkedin');
        $output = ['variants' => [['channel_id' => 1, 'body' => str_repeat('a', 41)]], 'link' => null, 'link_title' => null, 'link_description' => null, 'alt_text' => null, 'rationale' => 'Why.'];

        expect(fn () => DraftResult::fromArray($output, $request, new DraftUsage('fake', 'fake-model', 1, 1)))
            ->toThrow(function (InvalidDraftOutput $failure) {
                expect($failure->getMessage())->toContain('The text for LinkedIn is 41 characters long.')->not->toContain('channel 1');
            });
    });

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

describe('the card of the link', function () {
    function requestWithCardChannel(?string $ideaTitle = 'The new roast', ?string $sourceUrl = 'https://example.com/roast'): DraftRequest
    {
        return new DraftRequest(
            briefTitle: 'Table Bay Coffee',
            briefBody: 'We roast coffee in Cape Town.',
            voiceRules: new VoiceRules([]),
            channels: [
                new TargetChannel(1, 'facebook', new ChannelCapabilities(500, linkPreviews: true)),
                new TargetChannel(2, 'linkedin', new ChannelCapabilities(500, linkPreviews: true, linkTitleLength: 20, linkDescriptionLength: 30)),
                new TargetChannel(3, 'linkedin', new ChannelCapabilities(500, linkPreviews: true, linkTitleLength: 12)),
            ],
            ideaTitle: $ideaTitle,
            sourceUrl: $sourceUrl,
        );
    }

    /**
     * @return list<array{string|null, string|null}> title and description per channel
     */
    function cardsOf(DraftResult $result): array
    {
        return array_map(fn (array $variant): array => [$variant['link_title'], $variant['link_description']], $result->variants());
    }

    it('gives the title and the description to the channels that need a card, each within its own limits', function () {
        $result = draftResultFor(requestWithCardChannel(), ['link_title' => 'A lighter roast', 'link_description' => 'How it tastes.']);

        expect(cardsOf($result))->toBe([
            [null, null],
            ['A lighter roast', 'How it tastes.'],
            // No description limit: this channel shows none. The title is cut at a word.
            ['A lighter', null],
        ]);
    });

    it('falls back to the title of the idea when the model wrote none', function (?string $title) {
        expect(cardsOf(draftResultFor(requestWithCardChannel(), ['link_title' => $title])))->toBe([
            [null, null],
            ['The new roast', null],
            ['The new', null],
        ]);
    })->with(['null' => [null], 'empty' => [''], 'blank' => [" \n "]]);

    it('has no title when there is neither one from the model nor an idea', function () {
        $result = draftResultFor(requestWithCardChannel(ideaTitle: null), ['link_title' => null, 'link_description' => 'How it tastes.']);

        expect(cardsOf($result))->toBe([[null, null], [null, 'How it tastes.'], [null, null]]);
    });

    it('makes the title one line of plain text', function () {
        $result = draftResultFor(requestWithCardChannel(), ['link_title' => "  A\tlighter\r\n roast\u{0000}\u{200B} "]);

        expect($result->variants()[1]['link_title'])->toBe('A lighter roast');
    });

    it('cuts in the middle of a word when there is no earlier place to cut', function () {
        $result = draftResultFor(requestWithCardChannel(), ['link_title' => 'Supercalifragilisticexpialidocious']);

        expect($result->variants()[1]['link_title'])->toBe('Supercalifragilistic')
            ->and($result->variants()[2]['link_title'])->toBe('Supercalifra');
    });

    it('has no card without a link, whatever the model wrote', function (array $output, ?string $sourceUrl) {
        $result = draftResultFor(requestWithCardChannel(sourceUrl: $sourceUrl), ['link_title' => 'A title', 'link_description' => 'A description.', ...$output]);

        expect($result->link)->toBeNull()
            ->and(cardsOf($result))->toBe([[null, null], [null, null], [null, null]]);
    })->with([
        'the model set no link' => [['link' => null], 'https://example.com/roast'],
        'the link was dropped' => [['link' => 'https://elsewhere.example/'], 'https://example.com/roast'],
        'the author gave no link' => [['link' => 'https://example.com/roast'], null],
    ]);

    it('keeps the card when a generator reports the usage of two attempts', function () {
        $result = draftResultFor(requestWithCardChannel(), ['link_title' => 'A lighter roast']);

        expect(cardsOf($result->withUsage(new DraftUsage('fake', 'fake-model', 2, 2, 2))))->toBe(cardsOf($result));
    });

    it('requires both fields in the answer, as a text or as null', function () {
        $output = validOutput();
        unset($output['link_title']);

        expect(DraftSchema::problems($output))->toBe(['The draft has no [link_title].'])
            ->and(DraftSchema::problems([...validOutput(), 'link_description' => 5]))->toBe(['The draft [link_description] has a value of the wrong type.']);
    });
});
