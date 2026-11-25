<?php

namespace App\Domain\Drafting;

/**
 * A post of the same workspace that did well, shown to the model as an
 * example of the voice. Filled from M6 (insights); empty until then.
 */
final readonly class ExamplePost
{
    public function __construct(
        public string $platform,
        public string $body,
    ) {}

    /**
     * @return array{platform: string, body: string}
     */
    public function toArray(): array
    {
        return ['platform' => $this->platform, 'body' => $this->body];
    }
}
