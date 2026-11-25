<?php

namespace App\Domain\Drafting;

/**
 * The shape of a generated draft, as one JSON schema. A generator sends
 * definition() to the provider as the structured output format, and
 * DraftResult::fromArray() checks the answer with problems(), which reads
 * the same definition. Change the shape here and nowhere else.
 *
 * The schema stays inside what providers enforce natively: every object
 * lists all its properties as required and closes with
 * additionalProperties false, an optional value is a union with null, and
 * there are no length or number limits. Limits that depend on the request
 * (which channels, how long a text may be) are checked by DraftResult.
 * See docs/platform-notes/llm.md.
 */
final class DraftSchema
{
    public const NAME = 'post_draft';

    /**
     * @return array<string, mixed>
     */
    public static function definition(): array
    {
        return [
            'type' => 'object',
            'description' => 'One social media post, written once for each target channel.',
            'additionalProperties' => false,
            'required' => ['variants', 'link', 'link_title', 'link_description', 'alt_text', 'rationale'],
            'properties' => [
                'variants' => [
                    'type' => 'array',
                    'description' => 'Exactly one entry for each target channel.',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['channel_id', 'body'],
                        'properties' => [
                            'channel_id' => [
                                'type' => 'integer',
                                'description' => 'The channel_id of the target channel this text is for.',
                            ],
                            'body' => [
                                'type' => 'string',
                                'description' => 'The text of the post for this channel, within its length limit.',
                            ],
                        ],
                    ],
                ],
                'link' => [
                    'description' => 'One link to attach to the post, or null when there is none.',
                    'anyOf' => [['type' => 'string'], ['type' => 'null']],
                ],
                'link_title' => [
                    'description' => 'A short plain title for the card some channels show with the link, or null when there is no link.',
                    'anyOf' => [['type' => 'string'], ['type' => 'null']],
                ],
                'link_description' => [
                    'description' => 'One plain sentence for the same card, or null.',
                    'anyOf' => [['type' => 'string'], ['type' => 'null']],
                ],
                'alt_text' => [
                    'description' => 'Alternative text for an image that would suit the post, or null.',
                    'anyOf' => [['type' => 'string'], ['type' => 'null']],
                ],
                'rationale' => [
                    'type' => 'string',
                    'description' => 'One line on why this post fits the brief.',
                ],
            ],
        ];
    }

    /**
     * What is wrong with the data according to definition(). Empty when it
     * matches. The texts name the place and the expectation and never quote
     * a value.
     *
     * @return list<string>
     */
    public static function problems(mixed $data): array
    {
        return self::check($data, self::definition(), 'The draft');
    }

    /**
     * @param  array<array-key, mixed>  $schema
     * @return list<string>
     */
    private static function check(mixed $value, array $schema, string $path): array
    {
        if (is_array($schema['anyOf'] ?? null)) {
            foreach ($schema['anyOf'] as $option) {
                if (is_array($option) && self::check($value, $option, $path) === []) {
                    return [];
                }
            }

            return ["{$path} has a value of the wrong type."];
        }

        return match ($schema['type'] ?? null) {
            'object' => self::checkObject($value, $schema, $path),
            'array' => self::checkList($value, $schema, $path),
            'string' => is_string($value) ? [] : ["{$path} must be a text."],
            'integer' => is_int($value) ? [] : ["{$path} must be a whole number."],
            'null' => $value === null ? [] : ["{$path} must be null."],
            default => ["{$path} has a type the schema does not describe."],
        };
    }

    /**
     * @param  array<array-key, mixed>  $schema
     * @return list<string>
     */
    private static function checkObject(mixed $value, array $schema, string $path): array
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            return ["{$path} must be an object."];
        }

        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];
        $problems = [];

        foreach ($required as $name) {
            if (is_string($name) && ! array_key_exists($name, $value)) {
                $problems[] = "{$path} has no [{$name}].";
            }
        }

        foreach ($value as $name => $item) {
            if (! isset($properties[$name]) || ! is_array($properties[$name])) {
                if (($schema['additionalProperties'] ?? true) === false) {
                    $problems[] = "{$path} has a field that is not part of the format.";
                }

                continue;
            }

            array_push($problems, ...self::check($item, $properties[$name], "{$path} [{$name}]"));
        }

        return $problems;
    }

    /**
     * @param  array<array-key, mixed>  $schema
     * @return list<string>
     */
    private static function checkList(mixed $value, array $schema, string $path): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return ["{$path} must be a list."];
        }

        $items = is_array($schema['items'] ?? null) ? $schema['items'] : null;
        $problems = [];

        foreach ($value as $index => $item) {
            if ($items !== null) {
                array_push($problems, ...self::check($item, $items, $path.' item '.($index + 1)));
            }
        }

        return $problems;
    }
}
