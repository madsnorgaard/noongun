<?php

/*
 * Drafting with OpenAI, through PrismDraftGenerator and Prism's own OpenAI
 * provider. No test calls OpenAI: the HTTP client is faked, and the answers
 * are written by hand in the shape of the Responses API as documented on
 * 2026-11-25 (docs/platform-notes/llm.md). These tests hold what is sent,
 * how each kind of answer is read, and that the key stays out of everything
 * that is stored, logged or shown.
 */

use App\Domain\Drafting\Actions\GenerateDraft;
use App\Domain\Drafting\CostEstimator;
use App\Domain\Drafting\CredentialScope;
use App\Domain\Drafting\DraftBudget;
use App\Domain\Drafting\DraftGenerators;
use App\Domain\Drafting\DraftingConfig;
use App\Domain\Drafting\DraftingContext;
use App\Domain\Drafting\DraftSchema;
use App\Domain\Drafting\DraftUsage;
use App\Domain\Drafting\Exceptions\DraftGenerationFailed;
use App\Domain\Drafting\Exceptions\DraftingException;
use App\Domain\Drafting\Exceptions\InvalidDraftOutput;
use App\Domain\Drafting\GenerateDraftJob;
use App\Domain\Drafting\Generators\PrismDialect;
use App\Domain\Drafting\Generators\PrismDraftGenerator;
use App\Domain\Drafting\LlmKey;
use App\Domain\Drafting\Models\Brief;
use App\Domain\Drafting\Models\DraftRun;
use App\Domain\Drafting\Models\LlmCredential;
use App\Domain\Drafting\Planning\ProposalCost;
use App\Domain\Drafting\PromptRenderer;
use App\Domain\Workspaces\Models\Workspace;
use App\Domain\Workspaces\Role;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Monolog\Handler\TestHandler;
use Tests\Fakes\FakeDraftGenerator;

require_once __DIR__.'/../../Support/publishing.php';
require_once __DIR__.'/../../Support/posts.php';
require_once __DIR__.'/../../Support/proposals.php';

const OPENAI_TEST_KEY = 'sk-proj-NoonGunOpenAiTestKeyThatMustNeverLeak-0123456789';

const OPENAI_TEST_MODEL = 'test-openai-model';

const OPENAI_URL = 'https://api.openai.com/v1/responses';

const SIDE_BY_SIDE_ANTHROPIC_OWN_KEY = 'sk-ant-api03-OwnAnthropicKeyThatMustNeverLeak-0123456789';

const SIDE_BY_SIDE_ANTHROPIC_INSTANCE_KEY = 'sk-ant-api03-InstanceAnthropicKeyNeverLeak-0123456789';

const SIDE_BY_SIDE_OPENAI_INSTANCE_KEY = 'sk-proj-InstanceOpenAiKeyThatMustNeverLeak-0123456789';

beforeEach(function () {
    config([
        'noongun.drafting.providers.openai' => [
            'generator' => PrismDraftGenerator::class,
            'model' => OPENAI_TEST_MODEL,
        ],
        'noongun.drafting.prices.openai' => [
            OPENAI_TEST_MODEL => ['input' => 2.00, 'output' => 10.00, 'cached_input' => 0.20, 'cache_write' => 2.50],
        ],
        'prism.providers.openai.api_key' => '',
        'prism.providers.openai.organization' => null,
        'prism.providers.openai.project' => null,
        'prism.providers.openai.url' => 'https://api.openai.com/v1',
    ]);

    $this->generator = app(PrismDraftGenerator::class);
    $this->request = plainDraftRequest(channels: 2);
    $this->context = openAiContext();
});

/**
 * Every prompt version in resources/prompts/draft.
 *
 * @return list<string>
 */
function draftPromptVersions(): array
{
    return array_map(basename(...), (array) glob(__DIR__.'/../../../resources/prompts/draft/*', GLOB_ONLYDIR));
}

function openAiContext(int $maxTokens = 2000, int $timeout = 20, string $promptVersion = 'v1'): DraftingContext
{
    return new DraftingContext(
        provider: 'openai',
        model: OPENAI_TEST_MODEL,
        maxTokens: $maxTokens,
        timeoutSeconds: $timeout,
        promptVersion: $promptVersion,
        key: new LlmKey('openai', OPENAI_TEST_KEY, CredentialScope::Workspace),
    );
}

/**
 * A completed answer of the Responses API: a reasoning item and one message
 * whose text is the JSON of the draft.
 *
 * @param  array<mixed>|string|null  $draft  the draft, or the raw text of the message; null gives a valid draft for the request of the test
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function openAiAnswer(array|string|null $draft = null, array $overrides = []): array
{
    $text = is_string($draft) ? $draft : (string) json_encode($draft ?? FakeDraftGenerator::outputFor(test()->request));

    return [
        'id' => 'resp_0f3a',
        'object' => 'response',
        'created_at' => 1795600000,
        'status' => 'completed',
        'error' => null,
        'incomplete_details' => null,
        'model' => 'test-openai-model-2026-10-01',
        'output' => [
            ['id' => 'rs_0f3a', 'type' => 'reasoning', 'summary' => []],
            [
                'id' => 'msg_0f3a',
                'type' => 'message',
                'status' => 'completed',
                'role' => 'assistant',
                'content' => [['type' => 'output_text', 'text' => $text, 'annotations' => []]],
            ],
        ],
        'usage' => [
            'input_tokens' => 900,
            'input_tokens_details' => ['cached_tokens' => 0],
            'output_tokens' => 250,
            'output_tokens_details' => ['reasoning_tokens' => 128],
            'total_tokens' => 1150,
        ],
        ...$overrides,
    ];
}

/**
 * An error body of the OpenAI API.
 *
 * @return array{error: array{message: string, type: string, param: null, code: string|null}}
 */
function openAiError(string $type, ?string $code, string $message): array
{
    return ['error' => ['message' => $message, 'type' => $type, 'param' => null, 'code' => $code]];
}

function openAiFailure(Closure $generate): DraftingException
{
    try {
        $generate();
    } catch (DraftingException $failure) {
        return $failure;
    }

    throw new LogicException('The generator did not fail.');
}

function everythingOfOpenAiFailure(Throwable $failure): string
{
    return implode("\n", [
        $failure->getMessage(),
        (string) json_encode(method_exists($failure, 'context') ? $failure->context() : []),
        (string) $failure,
        $failure->getTraceAsString(),
    ]);
}

describe('the request', function () {
    it('sends the rendered prompt, the strict schema and the limits, and nothing else, for every prompt version', function (string $version) {
        Http::fake([OPENAI_URL => Http::response(openAiAnswer())]);
        $prompt = app(PromptRenderer::class)->render($this->request, $version);

        $result = $this->generator->generate($this->request, openAiContext(promptVersion: $version));

        expect($result->bodies)->toHaveCount(2);

        Http::assertSentCount(1);
        Http::assertSent(function (Request $sent) use ($prompt) {
            expect($sent->url())->toBe(OPENAI_URL)
                ->and($sent->method())->toBe('POST')
                ->and($sent->header('Authorization'))->toBe(['Bearer '.OPENAI_TEST_KEY])
                ->and($sent->hasHeader('OpenAI-Organization'))->toBeFalse()
                ->and($sent->hasHeader('OpenAI-Project'))->toBeFalse()
                ->and($sent->hasHeader('x-api-key'))->toBeFalse();

            $body = $sent->data();

            expect(array_keys($body))->toEqualCanonicalizing(['model', 'input', 'max_output_tokens', 'tools', 'parallel_tool_calls', 'store', 'text'])
                ->and($body['tools'])->toBe([])
                ->and($body['model'])->toBe(OPENAI_TEST_MODEL)
                ->and($body['max_output_tokens'])->toBe(2000)
                ->and($body['parallel_tool_calls'])->toBeFalse()
                ->and($body['store'])->toBeFalse()
                ->and($body['text'])->toBe(['format' => [
                    'type' => 'json_schema',
                    'name' => DraftSchema::NAME,
                    'schema' => DraftSchema::definition(),
                    'strict' => true,
                ]]);

            expect($body['input'])->toBe([
                ['role' => 'system', 'content' => $prompt->system],
                ['role' => 'user', 'content' => [['type' => 'input_text', 'text' => $prompt->user]]],
            ]);

            return true;
        });
    })->with(draftPromptVersions());

    it('has more than one prompt version to send', function () {
        expect(draftPromptVersions())->toContain('v1')->toContain(DraftingConfig::promptVersion());
    });

    it('sends the configured effort as reasoning effort, and none when it is not set', function (mixed $configured, ?array $expected) {
        config(['noongun.drafting.providers.openai.effort' => $configured]);
        Http::fake([OPENAI_URL => Http::response(openAiAnswer())]);

        $this->generator->generate($this->request, $this->context);

        Http::assertSent(fn (Request $sent) => ($sent->data()['reasoning'] ?? null) === $expected);
    })->with([
        'low' => ['low', ['effort' => 'low']],
        'not set' => [null, null],
        'not a word' => ['{"tools":[]}', null],
    ]);

    it('sends the organization and project of the instance with the key of the instance only', function (CredentialScope $scope, bool $sent) {
        config(['prism.providers.openai.organization' => 'org-of-the-operator', 'prism.providers.openai.project' => 'proj_of_the_operator']);
        Http::fake([OPENAI_URL => Http::response(openAiAnswer())]);

        $this->generator->generate($this->request, new DraftingContext('openai', OPENAI_TEST_MODEL, 2000, 20, 'v1', new LlmKey('openai', OPENAI_TEST_KEY, $scope)));

        $request = Http::recorded()->first()[0];

        expect($request->hasHeader('OpenAI-Organization'))->toBe($sent)
            ->and($request->hasHeader('OpenAI-Project'))->toBe($sent)
            ->and(str_contains((string) json_encode($request->headers()), 'of-the-operator'))->toBe($sent)
            ->and($request->header('Authorization'))->toBe(['Bearer '.OPENAI_TEST_KEY])
            ->and(config('prism.providers.openai.organization'))->toBe('org-of-the-operator');
    })->with([
        'a key a workspace brought' => [CredentialScope::Workspace, false],
        'the key of the instance' => [CredentialScope::Instance, true],
    ]);

    it('uses the timeout of the context', function () {
        Http::fake([OPENAI_URL => Http::response(openAiAnswer())]);

        $this->generator->generate($this->request, openAiContext(timeout: 77));

        Http::assertSent(fn (Request $sent) => true);
        expect(Http::recorded()->first()[0]->toPsrRequest()->getUri()->getHost())->toBe('api.openai.com');
    });

    it('keeps what differs between providers in the dialect, with no model name, version or price', function () {
        $dialect = (string) file_get_contents(app_path('Domain/Drafting/Generators/PrismDialect.php'));

        expect($dialect)->not->toMatch('/claude|opus|sonnet|haiku|gpt-|gemini|anthropic-version|20\d\d-\d\d-\d\d/i')
            ->and($dialect)->not->toMatch('/config\(|env\(/')
            ->and(PrismDialect::requestOptions('openai', null))->toBe(['schema' => ['strict' => true], 'store' => false])
            ->and(PrismDialect::requestOptions('anthropic', null))->toBe([])
            ->and(PrismDialect::requestOptions('anthropic', 'low'))->toBe(['effort' => 'low']);
    });
});

describe('the draft schema as a strict schema', function () {
    /**
     * The rules of OpenAI's structured outputs for a strict schema, as
     * documented on 2026-11-25. Returns what breaks them.
     *
     * @param  array<array-key, mixed>  $schema
     * @return list<string>
     */
    function strictSchemaProblems(array $schema, string $path = 'root', int $depth = 1): array
    {
        $allowed = ['type', 'description', 'properties', 'required', 'additionalProperties', 'items', 'anyOf', 'enum', 'const'];
        $problems = [];

        foreach (array_keys($schema) as $keyword) {
            if (! in_array($keyword, $allowed, true)) {
                $problems[] = "{$path} uses [{$keyword}].";
            }
        }

        if ($depth > 10) {
            $problems[] = "{$path} is nested deeper than 10 levels.";
        }

        if ($path === 'root' && (($schema['type'] ?? null) !== 'object' || isset($schema['anyOf']))) {
            $problems[] = 'The root is not a plain object.';
        }

        if (($schema['type'] ?? null) === 'object') {
            $properties = $schema['properties'] ?? [];

            if (($schema['additionalProperties'] ?? null) !== false) {
                $problems[] = "{$path} does not close with additionalProperties false.";
            }

            if (array_keys($properties) !== ($schema['required'] ?? null)) {
                $problems[] = "{$path} does not require every property.";
            }

            foreach ($properties as $name => $property) {
                array_push($problems, ...strictSchemaProblems($property, "{$path}.{$name}", $depth + 1));
            }
        }

        if (($schema['type'] ?? null) === 'array') {
            array_push($problems, ...strictSchemaProblems($schema['items'] ?? [], "{$path}[]", $depth + 1));
        }

        foreach ($schema['anyOf'] ?? [] as $index => $option) {
            array_push($problems, ...strictSchemaProblems($option, "{$path}|{$index}", $depth));
        }

        if (! isset($schema['anyOf']) && ! in_array($schema['type'] ?? null, ['object', 'array', 'string', 'integer', 'number', 'boolean', 'null'], true)) {
            $problems[] = "{$path} has no supported type.";
        }

        return $problems;
    }

    it('is one OpenAI accepts: closed objects, every property required, null instead of optional', function () {
        expect(strictSchemaProblems(DraftSchema::definition()))->toBe([]);
    });

    it('would be caught if it stopped being one', function () {
        $schema = DraftSchema::definition();
        $schema['required'] = ['variants'];
        $schema['properties']['rationale']['maxLength'] = 200;

        expect(strictSchemaProblems($schema))->toBe(['root does not require every property.', 'root.rationale uses [maxLength].']);
    });
});

describe('the answer', function () {
    it('becomes a validated result, with the model that answered', function () {
        Http::fake([OPENAI_URL => Http::response(openAiAnswer([
            'variants' => [
                ['channel_id' => 2, 'body' => '  Second channel.  '],
                ['channel_id' => 1, 'body' => 'First channel.'],
            ],
            'link' => 'https://evil.example/not-given',
            'alt_text' => null,
            'rationale' => 'It fits the brief.',
        ]))]);

        $result = $this->generator->generate($this->request, $this->context);

        expect($result->bodies)->toBe([1 => 'First channel.', 2 => 'Second channel.'])
            ->and($result->link)->toBeNull()
            ->and($result->linkDropped)->toBeTrue()
            ->and($result->usage->toArray())->toBe([
                'provider' => 'openai',
                'model' => 'test-openai-model-2026-10-01',
                'input_tokens' => 900,
                'output_tokens' => 250,
                'attempts' => 1,
            ]);
    });

    it('counts cached input as input and reasoning as part of the output, once', function () {
        Http::fake([OPENAI_URL => Http::response(openAiAnswer(overrides: ['usage' => [
            'input_tokens' => 12000,
            'input_tokens_details' => ['cached_tokens' => 4000, 'cache_write_tokens' => 7000],
            'output_tokens' => 1186,
            'output_tokens_details' => ['reasoning_tokens' => 1024],
            'total_tokens' => 13186,
        ]]))]);

        $usage = $this->generator->generate($this->request, $this->context)->usage;

        expect($usage->inputTokens)->toBe(12000)
            ->and($usage->cacheReadTokens)->toBe(4000)
            ->and($usage->cacheWriteTokens)->toBe(7000)
            ->and($usage->outputTokens)->toBe(1186);

        // 1000 x 2.00 + 4000 x 0.20 + 7000 x 2.50 + 1186 x 10.00, per million.
        expect(app(CostEstimator::class)->estimate($usage, OPENAI_TEST_MODEL)?->amount)->toBe('0.032160');
    });

    it('does not trust the strict schema: an answer outside the format is asked for once more and then refused', function (array $draft) {
        Http::fake([OPENAI_URL => Http::sequence()
            ->push(openAiAnswer($draft))
            ->push(openAiAnswer($draft))]);

        $failure = openAiFailure(fn () => $this->generator->generate($this->request, $this->context));

        Http::assertSentCount(2);
        expect($failure)->toBeInstanceOf(InvalidDraftOutput::class)
            ->and($failure->usage?->attempts)->toBe(2)
            ->and($failure->usage?->inputTokens)->toBe(1800);
    })->with([
        'a field that is not part of the format' => [['variants' => [['channel_id' => 1, 'body' => 'One.'], ['channel_id' => 2, 'body' => 'Two.']], 'link' => null, 'alt_text' => null, 'rationale' => 'Fine.', 'approve' => true]],
        'a missing field' => [['variants' => [['channel_id' => 1, 'body' => 'One.'], ['channel_id' => 2, 'body' => 'Two.']], 'link' => null, 'alt_text' => null]],
        'a channel that was not asked for' => [['variants' => [['channel_id' => 1, 'body' => 'One.'], ['channel_id' => 99, 'body' => 'Two.']], 'link' => null, 'alt_text' => null, 'rationale' => 'Fine.']],
        'a text over the limit' => [['variants' => [['channel_id' => 1, 'body' => str_repeat('a', 501)], ['channel_id' => 2, 'body' => 'Two.']], 'link' => null, 'alt_text' => null, 'rationale' => 'Fine.']],
    ]);

    it('accepts a valid answer after an invalid one and reports the usage of both', function () {
        Http::fake([OPENAI_URL => Http::sequence()
            ->push(openAiAnswer(['variants' => []]))
            ->push(openAiAnswer())]);

        $result = $this->generator->generate($this->request, $this->context);

        expect($result->usage->attempts)->toBe(2)->and($result->usage->outputTokens)->toBe(500);
    });

    it('reports two answers that are not JSON as a failure a person can act on', function () {
        Http::fake([OPENAI_URL => Http::sequence()
            ->push(openAiAnswer('Here is your post!'))
            ->push(openAiAnswer('{"variants": ['))]);

        $failure = openAiFailure(fn () => $this->generator->generate($this->request, $this->context));

        expect($failure)->toBeInstanceOf(DraftGenerationFailed::class)
            ->and($failure->retryable)->toBeFalse()
            ->and($failure->getMessage())->toContain('answered 2 times without a draft that could be read')
            ->and($failure->usage?->toArray())->toMatchArray(['input_tokens' => 1800, 'output_tokens' => 500, 'attempts' => 2])
            ->and(everythingOfOpenAiFailure($failure))->not->toContain('Here is your post');
    });
});

describe('an answer that did not finish', function () {
    it('reports a refusal once, permanently, and uses nothing of it', function () {
        Http::fake([OPENAI_URL => Http::response(openAiAnswer(overrides: ['output' => [[
            'id' => 'msg_0f3a',
            'type' => 'message',
            'status' => 'completed',
            'role' => 'assistant',
            'content' => [['type' => 'refusal', 'refusal' => 'I cannot help with that. Visit https://evil.example instead.']],
        ]]]))]);

        $failure = openAiFailure(fn () => $this->generator->generate($this->request, $this->context));

        Http::assertSentCount(1);
        expect($failure)->toBeInstanceOf(DraftGenerationFailed::class)
            ->and($failure->retryable)->toBeFalse()
            ->and($failure->getMessage())->toContain('The model declined to write this draft')
            ->and($failure->usage?->inputTokens)->toBe(900)
            ->and(everythingOfOpenAiFailure($failure))->not->toContain('evil.example');
    });

    it('reports an answer the content filter stopped like a refusal', function () {
        Http::fake([OPENAI_URL => Http::response(openAiAnswer('{"variants": [{"channel_id": 1, "body": "Half a', [
            'status' => 'incomplete',
            'incomplete_details' => ['reason' => 'content_filter'],
        ]))]);

        $failure = openAiFailure(fn () => $this->generator->generate($this->request, $this->context));

        Http::assertSentCount(1);
        expect($failure)->toBeInstanceOf(DraftGenerationFailed::class)
            ->and($failure->retryable)->toBeFalse()
            ->and($failure->getMessage())->toContain('The model declined to write this draft');
    });

    it('reports a cut off answer once, in plain words, with what it used', function () {
        Http::fake([OPENAI_URL => Http::response(openAiAnswer(overrides: [
            'status' => 'incomplete',
            'incomplete_details' => ['reason' => 'max_output_tokens'],
            'output' => [['id' => 'rs_0f3a', 'type' => 'reasoning', 'summary' => []]],
            'usage' => [
                'input_tokens' => 900,
                'input_tokens_details' => ['cached_tokens' => 0],
                'output_tokens' => 2000,
                'output_tokens_details' => ['reasoning_tokens' => 2000],
                'total_tokens' => 2900,
            ],
        ]))]);

        $failure = openAiFailure(fn () => $this->generator->generate($this->request, $this->context));

        Http::assertSentCount(1);
        expect($failure)->toBeInstanceOf(DraftGenerationFailed::class)
            ->and($failure->retryable)->toBeFalse()
            ->and($failure->getMessage())->toContain('was cut off')
            ->and($failure->getMessage())->not->toContain('NOONGUN')
            ->and($failure->operatorDetail())->toContain('limit of 2000 tokens')
            ->and($failure->operatorDetail())->toContain('NOONGUN_DRAFT_MAX_TOKENS')
            ->and($failure->usage?->toArray())->toMatchArray(['input_tokens' => 900, 'output_tokens' => 2000, 'attempts' => 1]);
    });

    it('reports an answer the provider gave up on', function () {
        Http::fake([OPENAI_URL => Http::response(openAiAnswer(overrides: [
            'status' => 'failed',
            'error' => ['code' => 'server_error', 'message' => 'The model failed to generate a response.'],
            'output' => [],
        ]))]);

        $failure = openAiFailure(fn () => $this->generator->generate($this->request, $this->context));

        expect($failure)->toBeInstanceOf(DraftGenerationFailed::class)
            ->and($failure->retryable)->toBeTrue()
            ->and($failure->getMessage())->toContain('problem of its own')
            ->and($failure->usage?->inputTokens)->toBe(900);
    });
});

describe('a failed call', function () {
    it('maps the failure', function (int $status, array $body, bool $retryable, string $says, array $context) {
        Http::fake([OPENAI_URL => Http::response($body, $status, ['retry-after' => '20'])]);

        $failure = openAiFailure(fn () => $this->generator->generate($this->request, $this->context));

        Http::assertSentCount(1);
        expect($failure)->toBeInstanceOf(DraftGenerationFailed::class)
            ->and($failure->retryable)->toBe($retryable)
            ->and($failure->getMessage())->toContain($says)
            ->and($failure->provider)->toBe('openai')
            ->and($failure->usage)->toBeNull()
            ->and($failure->getPrevious())->toBeNull()
            ->and($failure->context())->toMatchArray($context);
    })->with([
        'rejected key' => [401, openAiError('invalid_request_error', 'invalid_api_key', 'Incorrect API key provided: sk-proj-********6789.'), false, 'did not accept the API key', ['status' => 401, 'code' => 'invalid_api_key']],
        'country not supported' => [403, openAiError('request_forbidden', 'unsupported_country_region_territory', 'Country, region, or territory not supported'), false, 'is not allowed to do this with the provider', ['status' => 403]],
        'unknown model' => [404, openAiError('invalid_request_error', 'model_not_found', 'The model `test-openai-model` does not exist or you do not have access to it.'), false, 'does not know the model this instance is set up to use', ['status' => 404]],
        'schema refused' => [400, openAiError('invalid_request_error', 'invalid_json_schema', "Invalid schema for response_format 'post_draft': In context=(), 'required' is required to be supplied."), false, 'The provider refused the request. The operator of this instance can find the reason in the log.', ['status' => 400, 'error' => 'invalid_request_error', 'code' => 'invalid_json_schema']],
        'rate limit' => [429, openAiError('rate_limit_error', 'rate_limit_exceeded', 'Rate limit reached for requests'), true, 'limiting how fast', ['status' => 429, 'retry_after' => 20]],
        'slow down' => [429, openAiError('rate_limit_error', 'slow_down', 'Slow down'), true, 'limiting how fast', ['status' => 429]],
        'quota used up' => [429, openAiError('insufficient_quota', 'insufficient_quota', 'You exceeded your current quota, please check your plan and billing details.'), false, 'has no credit left', ['status' => 429, 'error' => 'insufficient_quota']],
        'credit used up' => [429, openAiError('insufficient_quota', 'credit_balance_exhausted', 'No prepaid credits remain.'), false, 'has no credit left', ['status' => 429, 'error' => 'credit_balance_exhausted']],
        'spend limit reached' => [429, openAiError('insufficient_quota', 'project_spend_limit_exceeded', 'The project reached its spend limit.'), false, 'has no credit left', ['status' => 429]],
        'server error' => [500, openAiError('server_error', null, 'The server had an error while processing your request'), true, 'problem of its own', ['status' => 500, 'error' => 'server_error']],
        'overloaded' => [503, openAiError('service_unavailable_error', 'server_is_overloaded', 'Model temporarily overloaded'), true, 'problem of its own', ['status' => 503]],
        'an error page that is not JSON' => [502, [], true, 'problem of its own', ['status' => 502]],
    ]);

    it('reports a timeout as worth trying again', function () {
        Http::fake([OPENAI_URL => Http::failedConnection('cURL error 28: Operation timed out')]);

        $failure = openAiFailure(fn () => $this->generator->generate($this->request, $this->context));

        expect($failure->retryable)->toBeTrue()
            ->and($failure->getMessage())->toContain('within 20 seconds')
            ->and($failure->context())->toMatchArray(['error' => 'connection'])
            ->and($failure->usage?->toArray())->toMatchArray(['input_tokens' => 0, 'output_tokens' => 0, 'attempts' => 1]);
    });

    it('records nothing for a provider that could not be reached at all', function () {
        Http::fake([OPENAI_URL => Http::failedConnection('cURL error 7: Failed to connect to api.openai.com port 443')]);

        $failure = openAiFailure(fn () => $this->generator->generate($this->request, $this->context));

        expect($failure->retryable)->toBeTrue()->and($failure->usage)->toBeNull();
    });

    it('keeps the usage of the first attempt when the second call fails', function () {
        Http::fake([OPENAI_URL => Http::sequence()
            ->push(openAiAnswer(['variants' => []]))
            ->push(openAiError('server_error', null, 'The server had an error'), 500)]);

        $failure = openAiFailure(fn () => $this->generator->generate($this->request, $this->context));

        expect($failure->retryable)->toBeTrue()
            ->and($failure->usage?->toArray())->toMatchArray(['input_tokens' => 900, 'output_tokens' => 250, 'attempts' => 1]);
    });
});

describe('what a member reads', function () {
    it('names no setting, model, status code or error name of the provider', function (int $status, array $body) {
        Http::fake([OPENAI_URL => Http::response($body, $status)]);

        $message = openAiFailure(fn () => $this->generator->generate($this->request, $this->context))->getMessage();

        expect($message)->not->toMatch('/NOONGUN|OPENAI|test-openai-model|\b[45]\d\d\b|_error|insufficient_quota|invalid_|response_format/');
    })->with([
        [401, openAiError('invalid_request_error', 'invalid_api_key', 'Incorrect API key provided.')],
        [400, openAiError('invalid_request_error', 'invalid_json_schema', 'Invalid schema for response_format.')],
        [404, openAiError('invalid_request_error', 'model_not_found', 'The model `test-openai-model` does not exist.')],
        [429, openAiError('insufficient_quota', 'insufficient_quota', 'You exceeded your current quota.')],
        [429, openAiError('rate_limit_error', 'rate_limit_exceeded', 'Rate limit reached.')],
        [500, openAiError('server_error', null, 'The server had an error.')],
    ]);
});

describe('the API key', function () {
    it('is in no message, context or trace of any failure, also when the provider repeats it', function (int $status, array $body) {
        Http::fake([OPENAI_URL => Http::response($body, $status)]);

        $failure = openAiFailure(fn () => $this->generator->generate($this->request, $this->context));

        expect(everythingOfOpenAiFailure($failure))->not->toContain(OPENAI_TEST_KEY)
            ->and($failure->getPrevious())->toBeNull();
    })->with([
        'repeated in the message' => [401, openAiError('invalid_request_error', 'invalid_api_key', 'Incorrect API key provided: '.OPENAI_TEST_KEY.'. You can find your API key at https://platform.openai.com/account/api-keys.')],
        'repeated in a bad request' => [400, openAiError('invalid_request_error', null, 'Header Authorization: Bearer '.OPENAI_TEST_KEY.' is malformed.')],
        'rate limit' => [429, openAiError('rate_limit_error', 'rate_limit_exceeded', 'Rate limit reached for '.OPENAI_TEST_KEY)],
        'server error' => [500, openAiError('server_error', null, 'The server had an error')],
    ]);

    it('is in no log record when a failure is reported', function () {
        $records = new TestHandler;
        Log::getLogger()->pushHandler($records);
        Http::fake([OPENAI_URL => Http::response(openAiError('invalid_request_error', 'invalid_api_key', 'Incorrect API key provided: '.OPENAI_TEST_KEY), 401)]);

        report(openAiFailure(fn () => $this->generator->generate($this->request, $this->context)));

        $logged = (string) json_encode(array_map(fn ($record) => $record->toArray(), $records->getRecords()));

        expect($records->getRecords())->not->toBeEmpty()
            ->and($logged)->not->toContain(OPENAI_TEST_KEY)
            ->and($logged)->not->toContain('NoonGunOpenAiTestKey');
    });

    it('is given out for its own provider only', function () {
        $key = new LlmKey('anthropic', 'sk-ant-api03-a-key-that-belongs-to-anthropic', CredentialScope::Workspace);

        expect(fn () => new DraftingContext('openai', OPENAI_TEST_MODEL, 2000, 20, 'v1', $key))
            ->toThrow(LogicException::class, 'cannot hold a key for [anthropic]')
            ->and(fn () => $key->revealFor('openai'))->toThrow(LogicException::class, 'only used with its own provider')
            ->and($key->revealFor('anthropic'))->toBe('sk-ant-api03-a-key-that-belongs-to-anthropic');

        Http::assertNothingSent();
    });
});

describe('two providers side by side', function () {
    beforeEach(function () {
        $this->travelTo('2026-11-25 10:00:00');
        Queue::fake();
        fakeChannelAdapter();

        config([
            'noongun.drafting.provider' => 'anthropic',
            'noongun.drafting.offered' => ['openai'],
            'noongun.drafting.providers.anthropic' => [
                'generator' => PrismDraftGenerator::class,
                'api_key' => SIDE_BY_SIDE_ANTHROPIC_INSTANCE_KEY,
                'model' => 'test-anthropic-model',
            ],
            'noongun.drafting.providers.openai.api_key' => SIDE_BY_SIDE_OPENAI_INSTANCE_KEY,
            'prism.providers.anthropic.api_key' => '',
            'prism.providers.anthropic.url' => 'https://api.anthropic.com/v1',
        ]);

        $this->workspace = Workspace::factory()->create(['timezone' => 'Africa/Johannesburg']);
        $this->owner = userWithRole($this->workspace, Role::Owner);
        $this->channel = channelIn($this->workspace);
        briefIn($this->workspace);

        inWorkspace($this->workspace, function () {
            LlmCredential::store('anthropic', SIDE_BY_SIDE_ANTHROPIC_OWN_KEY);
            LlmCredential::store('openai', OPENAI_TEST_KEY);
        });

        $this->request = draftRequestIn($this->workspace, [$this->channel]);

        $this->draft = fn (): DraftRun => inWorkspace($this->workspace, function (): DraftRun {
            $run = DraftRun::start($this->owner, app(DraftGenerators::class)->contextFor($this->workspace->refresh()), [], Brief::current());

            app(GenerateDraft::class)->handle($this->owner, $run, $this->request);

            return $run;
        });

        Http::fake([
            OPENAI_URL => Http::response(openAiAnswer()),
            'https://api.anthropic.com/*' => Http::response([
                'id' => 'msg_0f3a',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'test-anthropic-model-20261001',
                'content' => [['type' => 'text', 'text' => (string) json_encode(FakeDraftGenerator::outputFor($this->request))]],
                'stop_reason' => 'end_turn',
                'stop_sequence' => null,
                'usage' => ['input_tokens' => 800, 'output_tokens' => 200],
            ]),
        ]);
    });

    /**
     * Everything of every request that left: address, headers and body.
     */
    function everythingSent(): string
    {
        return Http::recorded()->map(fn (array $pair): string => $pair[0]->url()."\n".json_encode($pair[0]->headers())."\n".$pair[0]->body())->implode("\n");
    }

    it('sends a draft for a workspace that chose OpenAI to OpenAI only, with the OpenAI key of that workspace only', function () {
        app(DraftGenerators::class)->choose($this->workspace, 'openai');

        $run = ($this->draft)();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $sent) => $sent->url() === OPENAI_URL && $sent->header('Authorization') === ['Bearer '.OPENAI_TEST_KEY]);

        expect(everythingSent())->not->toContain('anthropic')
            ->not->toContain(SIDE_BY_SIDE_ANTHROPIC_OWN_KEY)
            ->not->toContain(SIDE_BY_SIDE_ANTHROPIC_INSTANCE_KEY)
            ->not->toContain(SIDE_BY_SIDE_OPENAI_INSTANCE_KEY);

        $stored = DB::table('draft_runs')->where('id', $run->id)->first();

        // 900 in and 250 out at 2.00 and 10.00 per million.
        expect([$stored->status, $stored->provider, $stored->model, $stored->credential_scope, $stored->cost_estimate])
            ->toBe(['succeeded', 'openai', OPENAI_TEST_MODEL, 'workspace', '0.004300'])
            ->and((string) json_encode($stored))->not->toContain(OPENAI_TEST_KEY);
    });

    it('sends a draft for a workspace on the default provider to Anthropic only, with the Anthropic key of that workspace only', function () {
        $run = ($this->draft)();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $sent) => str_starts_with($sent->url(), 'https://api.anthropic.com/') && $sent->header('x-api-key') === [SIDE_BY_SIDE_ANTHROPIC_OWN_KEY] && ! $sent->hasHeader('Authorization'));

        expect(everythingSent())->not->toContain('openai')
            ->not->toContain(OPENAI_TEST_KEY)
            ->not->toContain(SIDE_BY_SIDE_OPENAI_INSTANCE_KEY)
            ->not->toContain(SIDE_BY_SIDE_ANTHROPIC_INSTANCE_KEY)
            ->and(DB::table('draft_runs')->where('id', $run->id)->value('provider'))->toBe('anthropic');
    });

    it('sends what PromptRenderer gives to OpenAI, and nothing else of the workspace', function () {
        app(DraftGenerators::class)->choose($this->workspace, 'openai');

        ($this->draft)();

        $prompt = app(PromptRenderer::class)->render($this->request, DraftingConfig::promptVersion());
        $body = Http::recorded()->first()[0]->data();

        expect($body['input'])->toBe([
            ['role' => 'system', 'content' => $prompt->system],
            ['role' => 'user', 'content' => [['type' => 'input_text', 'text' => $prompt->user]]],
        ])
            ->and(array_keys($body))->toEqualCanonicalizing(['model', 'input', 'max_output_tokens', 'tools', 'parallel_tool_calls', 'store', 'text'])
            ->and((string) json_encode($body))->not->toContain($this->owner->email)
            ->not->toContain($this->workspace->name);
    });

    it('shows the cost of a week by the OpenAI prices, with the prompt version in use', function () {
        slotIn($this->workspace, $this->channel, 1, '09:00');
        app(DraftGenerators::class)->choose($this->workspace, 'openai');

        $cost = inWorkspace($this->workspace, function (): ?array {
            $context = app(DraftGenerators::class)->contextFor($this->workspace->refresh());
            $plan = planFor($this->workspace, '2026-11-30');
            $prompt = app(PromptRenderer::class)->render(draftRequestIn($this->workspace, [$this->channel]), $context->promptVersion);
            $bytes = strlen($prompt->system) + strlen($prompt->user) + strlen((string) json_encode(DraftSchema::definition()));

            expect($context->promptVersion)->toBe(DraftingConfig::promptVersion())
                ->and($plan->posts)->toHaveCount(1);

            // One post, 3 attempts of 2 answers each, every answer to the limit of
            // the context; input at the cache write price of 2.50, the dearest.
            $worst = app(CostEstimator::class)->atMost(new DraftUsage('openai', OPENAI_TEST_MODEL, ($bytes + 1024) * 6, 6 * $context->maxTokens));
            $cost = app(ProposalCost::class)->of($plan, Brief::current(), $context);

            expect($cost['worst']->amount ?? null)->toBe($worst?->amount);

            return $cost;
        });

        expect($cost)->not->toBeNull()
            ->and((float) $cost['expected']->amount)->toBeGreaterThan(0.0)
            ->and((float) $cost['worst']->amount)->toBeGreaterThan((float) $cost['expected']->amount);
    });

    it('counts a run that timed out towards the daily limits, and not one that never reached the provider', function () {
        app(DraftGenerators::class)->choose($this->workspace, 'openai');
        $used = fn (): int => inWorkspace($this->workspace, fn () => app(DraftBudget::class)->usedByWorkspace());

        Http::fake([OPENAI_URL => Http::failedConnection('cURL error 7: Failed to connect to api.openai.com port 443')]);

        expect(fn () => ($this->draft)())->toThrow(DraftGenerationFailed::class)
            ->and($used())->toBe(0);

        Http::swap(new Factory(app('events')));
        Http::preventStrayRequests();
        Http::fake([OPENAI_URL => Http::failedConnection('cURL error 28: Operation timed out after 20001 milliseconds')]);

        expect(fn () => ($this->draft)())->toThrow(DraftGenerationFailed::class)
            ->and($used())->toBe(1);

        $run = DB::table('draft_runs')->orderByDesc('id')->first();

        expect([$run->status, $run->input_tokens, $run->output_tokens, $run->attempts])->toBe(['failed', 0, 0, 1]);
    });

    it('queues nothing that holds a key when a week is proposed', function () {
        slotIn($this->workspace, $this->channel, 1, '09:00');
        app(DraftGenerators::class)->choose($this->workspace, 'openai');

        proposeWeek($this->workspace->refresh(), $this->owner);

        $jobs = Queue::pushed(GenerateDraftJob::class);

        expect($jobs)->not->toBeEmpty()
            ->and($jobs->map(fn (GenerateDraftJob $job): string => serialize($job))->implode("\n"))->not->toContain(OPENAI_TEST_KEY)
            ->not->toContain('NeverLeak');

        Http::assertNothingSent();
    });
});
