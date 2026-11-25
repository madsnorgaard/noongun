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

it('registers resources/prompts as the prompts view namespace', function () {
    expect(View::exists('prompts::draft.v1.system'))->toBeTrue()
        ->and(View::exists('prompts::draft.v1.user'))->toBeTrue()
        ->and($this->renderer->has('v1'))->toBeTrue()
        ->and($this->renderer->has(config('noongun.drafting.prompt_version')))->toBeTrue();
});

it('renders the request into a system and a user text for the version', function () {
    $prompt = $this->renderer->render(fullRequest(), 'v1');

    expect($prompt->version)->toBe('v1')
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
});

it('does not escape the text as HTML', function () {
    $prompt = $this->renderer->render(fullRequest(), 'v1');

    expect($prompt->user.$prompt->system)->not->toMatch('/&(amp|quot|lt|gt|#0?39);/');
});

it('leaves out what the request does not have', function () {
    $prompt = $this->renderer->render(plainDraftRequest(), 'v1');

    expect($prompt->user)
        ->toContain('<brief')
        ->toContain('<target_channels>')
        ->not->toContain('<pillar')
        ->not->toContain('<idea')
        ->not->toContain('<source_url>')
        ->not->toContain('<calls_to_action>')
        ->not->toContain('<example_posts>')
        ->not->toMatch("/\n{3,}/");
});

it('says the same thing for the same request', function () {
    expect($this->renderer->render(fullRequest(), 'v1'))->toEqual($this->renderer->render(fullRequest(), 'v1'));
});

it('keeps the system text free of anything from the request', function () {
    expect($this->renderer->render(fullRequest(), 'v1')->system)->toBe($this->renderer->render(plainDraftRequest(), 'v1')->system);
});

describe('what is sent to a provider', function () {
    it('holds the brief, voice rules, pillar, idea and channel limits of the workspace, and nothing else it owns', function () {
        $owner = userWithRole($this->workspace, Role::Owner);
        $channel = channelIn($this->workspace, [
            'display_name' => 'Page Name Kept Private',
            'external_id' => '990000000000001',
            'access_token' => 'page-access-token-kept-private',
            'refresh_token' => 'page-refresh-token-kept-private',
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

        $request = draftRequestIn($this->workspace, [$channel], $pillar, $idea);
        $prompt = $this->renderer->render($request, 'v1');
        $sent = $prompt->system."\n".$prompt->user;

        expect($sent)
            ->toContain('Our own brief text.')
            ->toContain('No contractions.')
            ->toContain('Our own pillar text.')
            ->toContain('Our own notes.')
            ->toContain('https://example.com/ours')
            ->toContain('channel_id="'.$channel->id.'" platform="facebook" max_characters="1234"');

        foreach ([
            'page-access-token-kept-private', 'page-refresh-token-kept-private', 'llm-key-kept-private', DRAFT_TEST_KEY,
            'Page Name Kept Private', '990000000000001', 'Workspace Name Kept Private',
            $owner->email, $owner->name, $theirUser->email, $theirUser->name,
            $unused->title,
            'Their brief', 'Their pillar', 'Their idea', 'Their notes', 'Their page', 'their-access-token', 'Their workspace',
        ] as $private) {
            expect($sent)->not->toContain($private);
        }
    });

    it('is built from a request that holds plain values only, with no model to reach through', function () {
        $request = draftRequestIn($this->workspace, 2, pillarIn($this->workspace), ideaIn($this->workspace));

        $holdsOnlyPlainValues = function (mixed $value) use (&$holdsOnlyPlainValues): bool {
            return match (true) {
                is_array($value) => collect($value)->every($holdsOnlyPlainValues),
                default => is_scalar($value) || $value === null,
            };
        };

        expect($holdsOnlyPlainValues($request->toArray()))->toBeTrue()
            ->and(array_keys($request->toArray()))->toBe(['brief', 'voice_rules', 'pillar', 'idea', 'source_url', 'channels', 'examples'])
            ->and(array_keys($request->toArray()['channels'][0]))->toBe([
                'channel_id', 'platform', 'max_body_length', 'link_previews', 'media_types', 'max_media_items', 'voice_rules',
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
