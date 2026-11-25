<?php

namespace App\Domain\Drafting\Casts;

use App\Domain\Drafting\VoiceRules;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * @implements CastsAttributes<VoiceRules, mixed>
 */
class AsVoiceRules implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): VoiceRules
    {
        $decoded = is_string($value) ? json_decode($value, true) : null;

        return VoiceRules::fromArray(is_array($decoded) ? $decoded : []);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        $rules = match (true) {
            $value instanceof VoiceRules => $value,
            is_array($value) => VoiceRules::fromArray($value),
            $value === null => new VoiceRules,
            default => throw new InvalidArgumentException('Voice rules must be a VoiceRules object or its array form.'),
        };

        return json_encode($rules->toArray(), JSON_THROW_ON_ERROR);
    }
}
