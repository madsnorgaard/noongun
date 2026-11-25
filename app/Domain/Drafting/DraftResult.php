<?php

namespace App\Domain\Drafting;

use App\Domain\Drafting\Exceptions\InvalidDraftOutput;

/**
 * One generated post: a text for each requested channel, a suggested link,
 * alternative text for an image, one line on why, and what it used.
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
    ) {}

    /**
     * Checks structured output against DraftSchema and against the request,
     * and builds the result. $data is the decoded JSON as the provider gave
     * it.
     *
     * Beyond the schema: there is exactly one text for each requested
     * channel and none for any other, no text is empty or longer than its
     * platform allows, the rationale is not empty, and a link is an http or
     * https address. An empty link or alternative text counts as none.
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

            if ($channel === null) {
                $problems[] = 'There is a text for a channel that was not asked for.';
            } elseif (isset($bodies[$channel->channelId])) {
                $problems[] = "There is more than one text for channel {$channel->channelId}.";
            } elseif ($body === '') {
                $problems[] = "The text for channel {$channel->channelId} is empty.";
            } elseif (! $channel->capabilities->fits($body)) {
                $problems[] = "The text for channel {$channel->channelId} is ".mb_strlen($body)." characters long. The limit is {$channel->capabilities->maxBodyLength}.";
            }

            if ($channel !== null) {
                $bodies[$channel->channelId] = $body;
            }
        }

        foreach ($request->channelIds() as $channelId) {
            if (! isset($bodies[$channelId])) {
                $problems[] = "There is no text for channel {$channelId}.";
            }
        }

        $link = self::optional($data['link']);

        if ($link !== null && ! self::isWebAddress($link)) {
            $problems[] = 'The link is not an http or https address.';
        }

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

        return new self($ordered, $link, self::optional($data['alt_text']), trim($data['rationale']), $usage);
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
        return new self($this->bodies, $this->link, $this->altText, $this->rationale, $usage);
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

    private static function optional(?string $value): ?string
    {
        $value = $value === null ? '' : trim($value);

        return $value === '' ? null : $value;
    }

    private static function isWebAddress(string $link): bool
    {
        return mb_strlen($link) <= 2048
            && filter_var($link, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($link, PHP_URL_SCHEME)), ['http', 'https'], true);
    }
}
