<?php

namespace App\Domain\Content\Exceptions;

use App\Domain\Content\Models\Post;
use DomainException;

/**
 * The post cannot be approved, scheduled or retried as it stands. errors()
 * has the shape of Laravel validation messages, so a controller can pass it
 * to ValidationException::withMessages().
 *
 * Keys: "variants" (the post has none), "variants.{id}.channel",
 * "variants.{id}.body" and "scheduled_at".
 */
class PostNotPublishable extends DomainException
{
    /**
     * @param  non-empty-array<string, non-empty-list<string>>  $errors
     */
    public function __construct(
        public readonly Post $post,
        private readonly array $errors,
    ) {
        parent::__construct(array_values($errors)[0][0]);
    }

    /**
     * @return non-empty-array<string, non-empty-list<string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
