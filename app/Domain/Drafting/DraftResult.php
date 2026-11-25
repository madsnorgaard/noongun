<?php

namespace App\Domain\Drafting;

use App\Domain\Drafting\Exceptions\InvalidDraftOutput;
use Illuminate\Support\Str;

/**
 * One generated post: a text for each requested channel, a link, alternative
 * text for an image, one line on why, and what it used.
 *
 * The link is never the model's own: it is one of the addresses the author
 * gave (the source of the request, or a call to action of the brief) or
 * there is none. $linkDropped says that the model suggested another one.
 *
 * Build it with fromArray(), which is the validator: a DraftResult that
 * exists matches DraftSchema and its request. It is only ever turned into a
 * post in the draft state, by Actions\CreateDraftFromResult.
 */
final readonly class DraftResult
{
    /**
     * @param  array<int, string>  $bodies  text per channel id
     */
    private function __construct(
        public array $bodies,
        public ?string $link,
        public ?string $altText,
        public string $rationale,
        public DraftUsage $usage,
        public bool $linkDropped = false,
    ) {}

    /**
     * Checks structured output against DraftSchema and against the request,
     * and builds the result. $data is the decoded JSON as the provider gave
     * it.
     *
     * Beyond the schema: there is exactly one text for each requested
     * channel and none for any other, no text is empty or longer than its
     * platform allows, and the rationale is not empty. An empty link or
     * alternative text counts as none.
     *
     * A link is kept only when it is the request's source URL or the URL of
     * one of the brief's calls to action, and then in the author's spelling.
     * Any other link is dropped without failing the draft: what is pasted
     * into an idea can steer a model, and a post must not carry an address
     * that nobody in the workspace chose.
     *
     * @throws InvalidDraftOutput carrying $usage, so the attempt is still paid for
     */
    public static function fromArray(mixed $data, DraftRequest $request, DraftUsage $usage): self
    {
        $problems = DraftSchema::problems($data);

        if ($problems !== [] || ! is_array($data)) {
            throw InvalidDraftOutput::because($usage->provider, $problems === [] ? ['The draft must be an object.'] : $problems, $usage);
        }

        /** @var array{variants: list<array{channel_id: int, body: string}>, link: string|null, alt_text: string|null, rationale: string} $data */
        $bodies = [];

        foreach ($data['variants'] as $variant) {
            $channel = $request->channel($variant['channel_id']);
            $body = trim($variant['body']);
            $name = $channel === null ? '' : self::nameOf($channel);

            if ($channel === null) {
                $problems[] = 'There is a text for a channel that was not asked for.';
            } elseif (isset($bodies[$channel->channelId])) {
                $problems[] = "There is more than one text for {$name}.";
            } elseif ($body === '') {
                $problems[] = "The text for {$name} is empty.";
            } elseif (! $channel->capabilities->fits($body)) {
                $problems[] = "The text for {$name} is ".mb_strlen($body)." characters long. The limit is {$channel->capabilities->maxBodyLength}.";
            }

            if ($channel !== null) {
                $bodies[$channel->channelId] = $body;
            }
        }

        foreach ($request->channels as $channel) {
            if (! isset($bodies[$channel->channelId])) {
                $problems[] = 'There is no text for '.self::nameOf($channel).'.';
            }
        }

        $suggested = self::optional($data['link']);
        $link = $suggested === null ? null : self::authorsLink($suggested, $request);

        if (trim($data['rationale']) === '') {
            $problems[] = 'The rationale is empty.';
        }

        if ($problems !== []) {
            throw InvalidDraftOutput::because($usage->provider, $problems, $usage);
        }

        $ordered = [];

        foreach ($request->channelIds() as $channelId) {
            $ordered[$channelId] = $bodies[$channelId];
        }

        return new self($ordered, $link, self::optional($data['alt_text']), trim($data['rationale']), $usage, $suggested !== null && $link === null);
    }

    public function bodyFor(int $channelId): ?string
    {
        return $this->bodies[$channelId] ?? null;
    }

    /**
     * The result with other usage, for a generator that needed a second
     * attempt and reports the sum.
     */
    public function withUsage(DraftUsage $usage): self
    {
        return new self($this->bodies, $this->link, $this->altText, $this->rationale, $usage, $this->linkDropped);
    }

    /**
     * The form SaveDraft takes: one entry per channel.
     *
     * @return list<array{channel_id: int, body: string, link: string|null}>
     */
    public function variants(): array
    {
        $variants = [];

        foreach ($this->bodies as $channelId => $body) {
            $variants[] = ['channel_id' => $channelId, 'body' => $body, 'link' => $this->link];
        }

        return $variants;
    }

    /**
     * A channel as a reader knows it: by its platform. The request holds no
     * channel names by design, and an id means nothing to a person.
     */
    private static function nameOf(TargetChannel $channel): string
    {
        return Str::headline($channel->platform);
    }

    private static function optional(?string $value): ?string
    {
        $value = $value === null ? '' : trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * The address of the author that the suggested link stands for, or null.
     */
    private static function authorsLink(string $suggested, DraftRequest $request): ?string
    {
        $wanted = self::comparable($suggested);

        if ($wanted === null) {
            return null;
        }

        $allowed = [$request->sourceUrl, ...array_column($request->callsToAction, 'url')];

        foreach ($allowed as $address) {
            $address = self::optional($address);

            if ($address !== null && self::comparable($address) === $wanted) {
                return $address;
            }
        }

        return null;
    }

    /**
     * An http or https address in the form two addresses are compared in:
     * scheme and authority in lower case, an empty path as "/", and
     * everything after that exactly as written. Null for anything else.
     */
    private static function comparable(string $address): ?string
    {
        if (! self::isWebAddress($address) || preg_match('~\A(https?)://([^/?#]*)(.*)\z~is', $address, $parts) !== 1) {
            return null;
        }

        $rest = $parts[3] === '' || $parts[3][0] !== '/' ? '/'.$parts[3] : $parts[3];

        return strtolower($parts[1]).'://'.strtolower($parts[2]).$rest;
    }

    private static function isWebAddress(string $link): bool
    {
        return mb_strlen($link) <= 2048
            && filter_var($link, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($link, PHP_URL_SCHEME)), ['http', 'https'], true);
    }
}
