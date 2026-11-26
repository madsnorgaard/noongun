<?php

namespace App\Domain\Drafting;

/**
 * Another post of the same week, told to the model in a few words so that
 * it does not write the same post twice: the pillar, the title of the idea
 * and the first line of the text, as far as each exists. Never a whole
 * text. Actions\ListPlannedAlongside builds the list.
 */
final readonly class PlannedAlongside
{
    /** The most entries one request carries. */
    public const MAX_ENTRIES = 12;

    /** The most characters of each part of an entry. */
    public const TEXT_LENGTH = 60;

    public ?string $pillar;

    public ?string $idea;

    public ?string $opening;

    public function __construct(?string $pillar = null, ?string $idea = null, ?string $opening = null)
    {
        $this->pillar = self::short($pillar);
        $this->idea = self::short($idea);
        $this->opening = self::short($opening);
    }

    public function isEmpty(): bool
    {
        return $this->pillar === null && $this->idea === null && $this->opening === null;
    }

    /**
     * @return array{pillar: string|null, idea: string|null, opening: string|null}
     */
    public function toArray(): array
    {
        return ['pillar' => $this->pillar, 'idea' => $this->idea, 'opening' => $this->opening];
    }

    /**
     * The first line that says something, cut to the length an entry may
     * have.
     */
    private static function short(?string $text): ?string
    {
        foreach (preg_split('/\R/u', $text ?? '') ?: [] as $line) {
            $line = trim((string) preg_replace('/\s+/u', ' ', $line));

            if ($line !== '') {
                return mb_substr($line, 0, self::TEXT_LENGTH);
            }
        }

        return null;
    }
}
