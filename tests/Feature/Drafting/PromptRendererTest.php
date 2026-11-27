<?php

/*
 * Brief section 9: never send platform tokens, other workspaces' content or
 * personal data beyond the brief to an LLM provider.
 *
 * PromptRenderer is the single place that decides what is sent. These tests
 * are the tripwire for that rule. If one of them fails because of a change
 * you made, the change is wrong, not the test.
 */

use App\Domain\Channels\Contracts\ChannelCapabilities;
use App\Domain\Drafting\DraftRequest;
use App\Domain\Drafting\ExamplePost;
use App\Domain\Drafting\Exceptions\UnknownPromptVersion;
use App\Domain\Drafting\PlannedAlongside;
use App\Domain\Drafting\PromptRenderer;
use App\Domain\Drafting\TargetChannel;
use App\Domain\Drafting\VoiceRules;
use App\Domain\Workspaces\Models\Workspace;
use App\Domain\Workspaces\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;

require_once __DIR__.'/../../Support/publishing.php';
require_once __DIR__.'/../../Support/posts.php';
require_once __DIR__.'/../../Support/sources.php';

beforeEach(function () {
    fakeChannelAdapter()->withCapabilities(new ChannelCapabilities(maxBodyLength: 1234, linkPreviews: true));

    $this->renderer = app(PromptRenderer::class);
    $this->workspace = Workspace::factory()->create(['name' => 'Workspace Name Kept Private']);
});

function fullRequest(): DraftRequest
{
    return new DraftRequest(
        briefTitle: 'Table Bay Coffee',
        briefBody: "We roast coffee in Cape Town.\n\nWe sell to cafes & offices.",
        voiceRules: new VoiceRules(
            ['No contractions.', 'Short hyphens instead of em dashes.'],
            ['facebook' => ['Old school Facebook tone.'], 'linkedin' => ['Professional but human.']],
        ),
        channels: [
            new TargetChannel(11, 'facebook', new ChannelCapabilities(5000, linkPreviews: true)),
            new TargetChannel(12, 'linkedin', new ChannelCapabilities(3000)),
        ],
        callsToAction: [['text' => 'Book a tasting', 'url' => 'https://example.com/tasting'], ['text' => 'Reply to say hello']],
        pillarName: 'Roasting',
        pillarDescription: 'How we roast and why.',
        ideaTitle: 'The new Ethiopian roast',
        ideaNotes: 'Lighter than "the usual" <medium> roast.',
        sourceUrl: 'https://example.com/roast?lot=7&year=2027',
        examples: [new ExamplePost('facebook', 'An earlier post that did well.')],
    );
}

/*
 * Every version that exists is held to the same line: a run of an earlier
 * week can still name v1 or v2, and v3 is what a new run is sent.
 */
dataset('prompt versions', ['v1', 'v2', 'v3']);

it('registers resources/prompts as the prompts view namespace', function (string $version) {
    expect(View::exists("prompts::draft.{$version}.system"))->toBeTrue()
        ->and(View::exists("prompts::draft.{$version}.user"))->toBeTrue()
        ->and($this->renderer->has($version))->toBeTrue()
        ->and($this->renderer->has(config('noongun.drafting.prompt_version')))->toBeTrue();
})->with('prompt versions');

it('covers every version that has templates', function () {
    $folders = array_map('basename', glob(resource_path('prompts/draft/*'), GLOB_ONLYDIR));

    expect($folders)->toEqualCanonicalizing(['v1', 'v2', 'v3'])
        ->and(config('noongun.drafting.prompt_version'))->toBe('v3');
});

it('renders the request into a system and a user text for the version', function (string $version) {
    $prompt = $this->renderer->render(fullRequest(), $version);

    expect($prompt->version)->toBe($version)
        ->and($prompt->system)->not->toBe('')
        ->and($prompt->user)
        ->toContain('Table Bay Coffee')
        ->toContain('We sell to cafes & offices.')
        ->toContain('Book a tasting (https://example.com/tasting)')
        ->toContain('Reply to say hello')
        ->toContain('- No contractions.')
        ->toContain('- Short hyphens instead of em dashes.')
        ->toContain('Roasting')
        ->toContain('How we roast and why.')
        ->toContain('The new Ethiopian roast')
        ->toContain('Lighter than "the usual" <medium> roast.')
        ->toContain('https://example.com/roast?lot=7&year=2027')
        ->toContain('channel_id="11" platform="facebook" max_characters="5000" link_previews="yes"')
        ->toContain('channel_id="12" platform="linkedin" max_characters="3000" link_previews="no"')
        ->toContain('- Old school Facebook tone.')
        ->toContain('- Professional but human.')
        ->toContain('An earlier post that did well.');
})->with('prompt versions');

it('does not escape the text as HTML', function (string $version) {
    $prompt = $this->renderer->render(fullRequest(), $version);

    expect($prompt->user.$prompt->system)->not->toMatch('/&(amp|quot|lt|gt|#0?39);/');
})->with('prompt versions');

it('leaves out what the request does not have', function (string $version) {
    $prompt = $this->renderer->render(plainDraftRequest(), $version);

    expect($prompt->user)
        ->toContain('<brief')
        ->toContain('<target_channels')
        ->not->toContain('<pillar')
        ->not->toContain('<idea')
        ->not->toContain('<source_url>')
        ->not->toContain('<calls_to_action>')
        ->not->toContain('<example_posts>')
        ->not->toMatch("/\n{3,}/");
})->with('prompt versions');

it('says the same thing for the same request', function (string $version) {
    expect($this->renderer->render(fullRequest(), $version))->toEqual($this->renderer->render(fullRequest(), $version));
})->with('prompt versions');

it('keeps the system text free of anything from the request', function (string $version) {
    expect($this->renderer->render(fullRequest(), $version)->system)->toBe($this->renderer->render(plainDraftRequest(), $version)->system);
})->with('prompt versions');

describe('v2, one idea written for each channel', function () {
    it('asks for a text of its own for each channel and says so in the system part', function () {
        $prompt = $this->renderer->render(fullRequest(), 'v2');

        expect($prompt->system)
            ->toContain('written separately for every target channel')
            ->toContain('Never paste one text into two channels')
            ->toContain('Every text of a request says the same thing')
            ->toContain('A text over the limit is thrown away')
            ->and($prompt->user)
            ->toContain('<target_channels count="2">')
            ->toContain('Write the post 2 times, once for each target channel above.');
    });

    it('puts the rules of a platform inside the channel they are for, and nowhere else', function () {
        $user = $this->renderer->render(fullRequest(), 'v2')->user;

        preg_match_all('~<channel ([^>]*)>(.*?)</channel>~s', $user, $channels, PREG_SET_ORDER);

        expect($channels)->toHaveCount(2)
            ->and($channels[0][1])->toBe('channel_id="11" platform="facebook" max_characters="5000" link_previews="yes" images="no"')
            ->and($channels[0][2])->toContain('- Old school Facebook tone.')->not->toContain('Professional but human.')
            ->and($channels[1][1])->toBe('channel_id="12" platform="linkedin" max_characters="3000" link_previews="no" images="no"')
            ->and($channels[1][2])->toContain('- Professional but human.')->not->toContain('Old school Facebook tone.')
            ->and(substr_count($user, 'Old school Facebook tone.'))->toBe(1)
            ->and(substr_count($user, 'Professional but human.'))->toBe(1);
    });

    it('says what a channel accepts from its capabilities', function () {
        $request = new DraftRequest(
            briefTitle: 'Table Bay Coffee',
            briefBody: 'We roast coffee in Cape Town.',
            voiceRules: new VoiceRules(['No contractions.']),
            channels: [new TargetChannel(21, 'facebook', new ChannelCapabilities(400, ['image/jpeg'], 1, linkPreviews: true))],
        );

        expect($this->renderer->render($request, 'v2')->user)
            ->toContain('<target_channels count="1">')
            ->toContain('channel_id="21" platform="facebook" max_characters="400" link_previews="yes" images="yes"')
            ->toContain('No rules of its own. The voice rules above apply.')
            ->toContain('Write the post for the target channel above.');
    });

    it('tells the model which channel needs a title for the link, and how long it may be', function () {
        $request = new DraftRequest(
            briefTitle: 'Table Bay Coffee',
            briefBody: 'We roast coffee in Cape Town.',
            voiceRules: new VoiceRules(['No contractions.']),
            channels: [
                new TargetChannel(21, 'facebook', new ChannelCapabilities(400, linkPreviews: true)),
                new TargetChannel(22, 'linkedin', new ChannelCapabilities(300, linkPreviews: true, linkTitleLength: 120, linkDescriptionLength: 200)),
            ],
            sourceUrl: 'https://example.com/roast',
        );

        $prompt = $this->renderer->render($request, 'v2');

        expect($prompt->user)
            ->toContain('channel_id="21" platform="facebook" max_characters="400" link_previews="yes" images="no">')
            ->toContain('channel_id="22" platform="linkedin" max_characters="300" link_previews="yes" images="no" link_title_max_characters="120">')
            ->and($prompt->system)
            ->toContain('- link_title: when you set a link, a short plain title for the card')
            ->toContain('- link_description: one plain sentence for the same card, or null.');
    });

    /*
     * A draft once ended with "the link is in the first comment". The post
     * carries its link itself, and no comment, image, poll or tag is ever
     * published with it. These hold that the system part keeps saying so,
     * by the points it makes and not by its wording as a whole.
     */
    it('says what is published with a post, so a text does not refer to anything else', function () {
        $system = $this->renderer->render(fullRequest(), 'v2')->system;

        expect($system)
            ->toContain('Only the text and the link are published.')
            ->toMatch('/must not promise or refer to/')
            ->toContain('attached to the post itself');

        foreach (['image', 'comment', 'poll', 'tag of a person'] as $notPublished) {
            expect($system)->toMatch('/^- Only the text and the link are published\\..*\\bNo\\b.*\\b'.preg_quote($notPublished, '/').'\\b/mi');
        }

        foreach (['in the comments', 'in the first comment', 'in the bio', 'below'] as $place) {
            expect($system)->toMatch('/^- The link,.*Never write that a link is .*\\b'.preg_quote($place, '/').'\\b/m');
        }

        expect($system)->toMatch('/^- The link,.*Only the text for a channel that shows link previews may say that the link is with the post\\.$/m');
    });

    it('keeps to the facts it is given', function () {
        $system = $this->renderer->render(fullRequest(), 'v2')->system;

        expect($system)
            ->toContain('Use only facts that the brief, the pillar, the idea or its notes state')
            ->toContain('When a detail is not given, write without it.');

        foreach (['numbers', 'dates', 'names', 'quotes'] as $invented) {
            expect($system)->toMatch('/no invented [^.]*\\b'.$invented.'\\b/');
        }
    });

    it('names no platform in the system part', function () {
        expect($this->renderer->render(fullRequest(), 'v2')->system)
            ->not->toMatch('/facebook|linkedin|instagram|twitter|threads|mastodon|bluesky|tiktok/i');
    });

    it('leaves v1 as it was: it says nothing about a title for the link', function () {
        $request = new DraftRequest(
            briefTitle: 'Table Bay Coffee',
            briefBody: 'We roast coffee in Cape Town.',
            voiceRules: new VoiceRules(['No contractions.']),
            channels: [new TargetChannel(22, 'linkedin', new ChannelCapabilities(300, linkPreviews: true, linkTitleLength: 120))],
        );

        $prompt = $this->renderer->render($request, 'v1');

        expect($prompt->system.$prompt->user)->not->toContain('link_title')
            ->and(hash('sha256', (string) file_get_contents(resource_path('prompts/draft/v1/system.blade.php'))))
            ->toBe('4a0303cc90279b2ce19f3a776bbc7dcb9241ce3f7388449f1c449d33d581fade')
            ->and(hash('sha256', (string) file_get_contents(resource_path('prompts/draft/v1/user.blade.php'))))
            ->toBe('7dbe9fbd07976934664ee70cc697ba3c240aff81ef8ec5aa23144123b1f29489');
    });

    it('leaves v2 as it was when v3 took over: draft runs refer to it', function () {
        $prompt = $this->renderer->render(fullRequest(), 'v2');

        expect($prompt->user)->not->toContain('performed')
            ->and(hash('sha256', (string) file_get_contents(resource_path('prompts/draft/v2/system.blade.php'))))
            ->toBe('9fd110463a9e4547f64ae49f0b3306616410ab516e3334487ce7bf630ac8e106')
            ->and(hash('sha256', (string) file_get_contents(resource_path('prompts/draft/v2/user.blade.php'))))
            ->toBe('18c37d3ae0f3ed3f79582b0f3282458f6631fa5fa92e000f1b286bf46054919e');
    });

    it('leaves v3 as it was released: it is what a new run is sent', function () {
        expect(hash('sha256', (string) file_get_contents(resource_path('prompts/draft/v3/system.blade.php'))))
            ->toBe('201179860c5b74082a819573a8c8ad6aade725e676c3e7c20d318398595d5152')
            ->and(hash('sha256', (string) file_get_contents(resource_path('prompts/draft/v3/user.blade.php'))))
            ->toBe('0a3f1a268ccd55369d8dfbcb5d59776324fc721c16f5dff0b1cc443a50d7c1a6');
    });

    it('changes nothing in v3 but the examples: its system part is that of v2, word for word', function () {
        expect($this->renderer->render(fullRequest(), 'v3')->system)->toBe($this->renderer->render(fullRequest(), 'v2')->system)
            ->and($this->renderer->render(fullRequest(), 'v3')->user)->toBe($this->renderer->render(fullRequest(), 'v2')->user);
    });
});

describe('what is sent to a provider', function () {
    it('holds the brief, voice rules, pillar, idea and channel limits of the workspace, and nothing else it owns', function (string $version) {
        $owner = userWithRole($this->workspace, Role::Owner);
        $channel = channelIn($this->workspace, [
            'display_name' => 'Page Name Kept Private',
            'external_id' => '990000000000001',
            'access_token' => 'page-access-token-kept-private',
            'refresh_token' => 'page-refresh-token-kept-private',
        ]);
        $second = channelIn($this->workspace, [
            'display_name' => 'Second Page Kept Private',
            'external_id' => '990000000000002',
            'access_token' => 'second-access-token-kept-private',
        ]);
        $brief = briefIn($this->workspace, ['title' => 'Our own brief', 'body' => 'Our own brief text.']);
        $pillar = pillarIn($this->workspace, ['name' => 'Our own pillar', 'description' => 'Our own pillar text.']);
        $idea = ideaIn($this->workspace, $pillar, ['title' => 'Our own idea', 'notes' => 'Our own notes.', 'source_url' => 'https://example.com/ours']);
        $unused = ideaIn($this->workspace, null, ['title' => 'An idea that was not asked for']);
        inWorkspace($this->workspace, fn () => DB::table('llm_credentials')->insert([
            'workspace_id' => $this->workspace->id, 'scope' => 'workspace', 'provider' => 'fake', 'api_key' => 'llm-key-kept-private',
        ]));

        $other = Workspace::factory()->create(['name' => 'Their workspace']);
        $theirUser = userWithRole($other, Role::Owner);
        briefIn($other, ['title' => 'Their brief', 'body' => 'Their brief text.']);
        pillarIn($other, ['name' => 'Their pillar', 'description' => 'Their pillar text.']);
        ideaIn($other, null, ['title' => 'Their idea', 'notes' => 'Their notes.']);
        channelIn($other, ['display_name' => 'Their page', 'access_token' => 'their-access-token']);

        $request = draftRequestIn($this->workspace, [$channel, $second], $pillar, $idea);
        $prompt = $this->renderer->render($request, $version);
        $sent = $prompt->system."\n".$prompt->user;

        expect($sent)
            ->toContain('Our own brief text.')
            ->toContain('No contractions.')
            ->toContain('Our own pillar text.')
            ->toContain('Our own notes.')
            ->toContain('https://example.com/ours')
            ->toContain('channel_id="'.$channel->id.'" platform="facebook" max_characters="1234"')
            ->toContain('channel_id="'.$second->id.'" platform="facebook" max_characters="1234"');

        foreach ([
            'page-access-token-kept-private', 'page-refresh-token-kept-private', 'llm-key-kept-private', DRAFT_TEST_KEY,
            'Page Name Kept Private', '990000000000001', 'Workspace Name Kept Private',
            'Second Page Kept Private', '990000000000002', 'second-access-token-kept-private',
            $owner->email, $owner->name, $theirUser->email, $theirUser->name,
            $unused->title,
            'Their brief', 'Their pillar', 'Their idea', 'Their notes', 'Their page', 'their-access-token', 'Their workspace',
        ] as $private) {
            expect($sent)->not->toContain($private);
        }
    })->with('prompt versions');

    it('is built from a request that holds plain values only, with no model to reach through', function () {
        $request = draftRequestIn($this->workspace, 2, pillarIn($this->workspace), ideaIn($this->workspace));

        $holdsOnlyPlainValues = function (mixed $value) use (&$holdsOnlyPlainValues): bool {
            return match (true) {
                is_array($value) => collect($value)->every($holdsOnlyPlainValues),
                default => is_scalar($value) || $value === null,
            };
        };

        expect($holdsOnlyPlainValues($request->toArray()))->toBeTrue()
            ->and(array_keys($request->toArray()))->toBe(['brief', 'voice_rules', 'pillar', 'idea', 'source_url', 'channels', 'examples', 'also_planned'])
            ->and(array_keys((new ExamplePost('facebook', 'A text.'))->toArray()))->toBe(['platform', 'body', 'channel_id', 'pillar', 'performed_well'])
            ->and(array_keys((new PlannedAlongside('A pillar'))->toArray()))->toBe(['pillar', 'idea', 'opening'])
            ->and(array_keys($request->toArray()['channels'][0]))->toBe([
                'channel_id', 'platform', 'max_body_length', 'link_previews', 'media_types', 'max_media_items',
                'link_title_length', 'link_description_length', 'voice_rules',
            ]);
    });

    it('is rendered in one place only: nothing else knows the prompts view namespace', function () {
        expect(sourcesMatching('/prompts::|PromptRenderer::NAMESPACE|addNamespace/'))->toBe(['Providers/AppServiceProvider.php'])
            ->and(sourcesMatching('/self::NAMESPACE/', 'Domain/Drafting/'))->toBe(['Domain/Drafting/PromptRenderer.php']);
    });

    it('comes from templates that receive the request data and reach for nothing else', function () {
        foreach (glob(resource_path('prompts/draft/*/*.blade.php')) as $template) {
            $source = preg_replace('/\{\{--.*?--\}\}/s', '', file_get_contents($template));

            expect($source)
                ->not->toMatch('/\{\{[^-]/', "{$template} must print with {!! !!}, never with escaping braces.")
                ->not->toMatch('/@php|@inject|@include|@auth|@can|@env|@session|<\?php/', "{$template} must not run code or pull in other views.")
                ->not->toMatch('/\b(app|auth|config|request|session|env|resolve|cache|DB)\s*\(|::/', "{$template} must only use the variables it is given.");
        }
    });
});

describe('prompt versions', function () {
    it('refuses a version that has no templates', function () {
        expect(fn () => $this->renderer->render(plainDraftRequest(), 'v999'))
            ->toThrow(UnknownPromptVersion::class, 'resources/prompts/draft/v999/system.blade.php does not exist')
            ->and($this->renderer->has('v999'))->toBeFalse();
    });

    it('refuses a version that is not a plain name, so it cannot point at another folder', function (string $version) {
        expect(fn () => $this->renderer->render(plainDraftRequest(), $version))->toThrow(UnknownPromptVersion::class, 'is not a prompt version')
            ->and($this->renderer->has($version))->toBeFalse();
    })->with(['', 'V1', 'v1.system', '../v1', 'v1/../v1', 'draft.v1', 'v 1']);
});
