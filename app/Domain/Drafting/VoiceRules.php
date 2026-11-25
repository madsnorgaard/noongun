<?php

namespace App\Domain\Drafting;

/**
 * How a workspace wants to sound, as data: rules for every channel and rules
 * for one platform. Stored on the brief as JSON and passed to the prompt as
 * they are. Nothing in the code knows what a rule says.
 *
 *     new VoiceRules(
 *         general: ['No contractions.', 'Short hyphens, never em dashes.'],
 *         platforms: ['facebook' => ['Personal and conversational. No hashtag walls.']],
 *     )
 */
final readonly class VoiceRules
{
    /** @var list<string> */
    public array $general;

    /** @var array<string, list<string>> */
    public array $platforms;

    /**
     * @param  array<array-key, mixed>  $general  rules for every channel
     * @param  array<array-key, mixed>  $platforms  rules per platform name, as in config('noongun.channels')
     */
    public function __construct(array $general = [], array $platforms = [])
    {
        $this->general = self::rules($general);

        $perPlatform = [];

        foreach ($platforms as $platform => $rules) {
            $rules = self::rules(is_array($rules) ? $rules : [$rules]);

            if (is_string($platform) && $platform !== '' && $rules !== []) {
                $perPlatform[$platform] = $rules;
            }
        }

        ksort($perPlatform);

        $this->platforms = $perPlatform;
    }

    /**
     * Reads the stored form. Anything that is not a rule is dropped, so a
     * damaged row gives fewer rules and never an error.
     *
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            is_array($data['general'] ?? null) ? $data['general'] : [],
            is_array($data['platforms'] ?? null) ? $data['platforms'] : [],
        );
    }

    /**
     * The rules that apply on one platform: the general ones first.
     *
     * @return list<string>
     */
    public function for(string $platform): array
    {
        return [...$this->general, ...($this->platforms[$platform] ?? [])];
    }

    public function isEmpty(): bool
    {
        return $this->general === [] && $this->platforms === [];
    }

    /**
     * @return array{general: list<string>, platforms: array<string, list<string>>}
     */
    public function toArray(): array
    {
        return ['general' => $this->general, 'platforms' => $this->platforms];
    }

    /**
     * @param  array<array-key, mixed>  $rules
     * @return list<string>
     */
    private static function rules(array $rules): array
    {
        $clean = [];

        foreach ($rules as $rule) {
            if (is_string($rule) && trim($rule) !== '') {
                $clean[] = trim($rule);
            }
        }

        return array_values(array_unique($clean));
    }
}
