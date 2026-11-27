<?php

use App\Domain\Channels\Contracts\ChannelCapabilities;
use App\Domain\Drafting\CredentialScope;
use App\Domain\Drafting\DraftRequest;
use App\Domain\Drafting\DraftUsage;
use App\Domain\Drafting\ExamplePost;
use App\Domain\Drafting\LlmKey;
use App\Domain\Drafting\TargetChannel;
use App\Domain\Drafting\VoiceRules;

describe('VoiceRules', function () {
    it('holds general rules and rules per platform, as data', function () {
        $rules = new VoiceRules(
            general: ['No contractions.', '  Short hyphens.  ', '', 'No contractions.'],
            platforms: ['linkedin' => ['Professional but human.'], 'facebook' => ['Conversational.'], 'x' => []],
        );

        expect($rules->general)->toBe(['No contractions.', 'Short hyphens.'])
            ->and($rules->platforms)->toBe(['facebook' => ['Conversational.'], 'linkedin' => ['Professional but human.']])
            ->and($rules->for('facebook'))->toBe(['No contractions.', 'Short hyphens.', 'Conversational.'])
            ->and($rules->for('mastodon'))->toBe(['No contractions.', 'Short hyphens.'])
            ->and($rules->isEmpty())->toBeFalse()
            ->and((new VoiceRules)->isEmpty())->toBeTrue();
    });

    it('survives its stored form', function () {
        $rules = new VoiceRules(['No contractions.'], ['facebook' => ['Conversational.']]);

        expect(VoiceRules::fromArray($rules->toArray()))->toEqual($rules);
    });

    it('reads a damaged row as fewer rules, never as an error', function () {
        $rules = VoiceRules::fromArray(['general' => 'not a list', 'platforms' => ['facebook' => 'One rule.', 3 => ['Lost.'], 'x' => [7, null]]]);

        expect($rules->general)->toBe([])
            ->and($rules->platforms)->toBe(['facebook' => ['One rule.']]);
    });
});

describe('DraftRequest', function () {
    it('needs at least one channel and each channel once', function () {
        $channel = new TargetChannel(1, 'facebook', new ChannelCapabilities(500));

        expect(fn () => new DraftRequest('Title', 'Body', new VoiceRules, []))->toThrow(InvalidArgumentException::class)
            ->and(fn () => new DraftRequest('Title', 'Body', new VoiceRules, [$channel, $channel]))->toThrow(InvalidArgumentException::class);
    });

    it('finds its channels by id', function () {
        $request = plainDraftRequest(2);

        expect($request->channelIds())->toBe([1, 2])
            ->and($request->channel(2)?->channelId)->toBe(2)
            ->and($request->channel(3))->toBeNull();
    });

    it('gives the prompt its whole content as plain data', function () {
        $request = new DraftRequest(
            briefTitle: 'Table Bay Coffee',
            briefBody: 'We roast coffee.',
            voiceRules: new VoiceRules(['No contractions.'], ['facebook' => ['Conversational.']]),
            channels: [new TargetChannel(7, 'facebook', new ChannelCapabilities(500, ['image/png'], 1, true))],
            callsToAction: [['text' => 'Book a tasting', 'url' => 'https://example.com/tasting'], ['text' => 'Say hello']],
            pillarName: 'Roasting',
            pillarDescription: 'How we roast.',
            ideaTitle: 'A new roast',
            sourceUrl: 'https://example.com/roast',
            examples: [new ExamplePost('facebook', 'An earlier post.')],
        );

        expect($request->toArray())->toBe([
            'brief' => [
                'title' => 'Table Bay Coffee',
                'body' => 'We roast coffee.',
                'calls_to_action' => [
                    ['text' => 'Book a tasting', 'url' => 'https://example.com/tasting'],
                    ['text' => 'Say hello', 'url' => null],
                ],
            ],
            'voice_rules' => ['general' => ['No contractions.'], 'platforms' => ['facebook' => ['Conversational.']]],
            'pillar' => ['name' => 'Roasting', 'description' => 'How we roast.'],
            'idea' => ['title' => 'A new roast', 'notes' => null],
            'source_url' => 'https://example.com/roast',
            'channels' => [[
                'channel_id' => 7,
                'platform' => 'facebook',
                'max_body_length' => 500,
                'link_previews' => true,
                'media_types' => ['image/png'],
                'max_media_items' => 1,
                'link_title_length' => null,
                'link_description_length' => null,
                'voice_rules' => ['Conversational.'],
            ]],
            'examples' => [['platform' => 'facebook', 'body' => 'An earlier post.', 'channel_id' => null, 'pillar' => null, 'performed_well' => false]],
            'also_planned' => [],
        ]);
    });
});

describe('DraftUsage', function () {
    it('adds a later attempt to an earlier one', function () {
        $sum = (new DraftUsage('fake', 'first-model', 100, 20))->plus(new DraftUsage('fake', 'second-model', 110, 25));

        expect($sum->toArray())->toBe([
            'provider' => 'fake',
            'model' => 'second-model',
            'input_tokens' => 210,
            'output_tokens' => 45,
            'attempts' => 2,
        ]);
    });

    it('refuses counts that cannot be', function () {
        expect(fn () => new DraftUsage('fake', 'fake-model', -1, 0))->toThrow(InvalidArgumentException::class)
            ->and(fn () => new DraftUsage('fake', 'fake-model', 1, 1, 0))->toThrow(InvalidArgumentException::class);
    });
});

describe('LlmKey', function () {
    it('gives its value only when asked', function () {
        $key = new LlmKey('fake', 'plain-llm-key-value', CredentialScope::Workspace);

        expect($key->reveal())->toBe('plain-llm-key-value')
            ->and(print_r($key, true))->not->toContain('plain-llm-key-value')
            ->and(var_export($key->__debugInfo(), true))->not->toContain('plain-llm-key-value')
            ->and(json_encode($key))->not->toContain('plain-llm-key-value')
            ->and(json_encode(draftingContext()))->not->toContain(DRAFT_TEST_KEY);
    });

    it('cannot be serialized, so it never reaches a queue or a cache', function () {
        expect(fn () => serialize(new LlmKey('fake', 'plain-llm-key-value', CredentialScope::Instance)))->toThrow(LogicException::class)
            ->and(fn () => serialize(draftingContext()))->toThrow(LogicException::class);
    });
});
