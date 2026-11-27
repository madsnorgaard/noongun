<?php

namespace App\Domain\Drafting;

use App\Domain\Drafting\Exceptions\UnknownPromptVersion;
use Illuminate\Contracts\View\Factory;

/**
 * Turns a DraftRequest into the prompt. This is the single place that
 * decides what a provider is sent: a generator sends system and user of the
 * result, the output schema, and nothing else.
 *
 * Templates are Blade files under resources/prompts/, registered as the
 * view namespace "prompts". A version is a folder:
 *
 *     resources/prompts/draft/v1/system.blade.php
 *     resources/prompts/draft/v1/user.blade.php
 *
 * To change a prompt, copy the folder to a new version and point
 * NOONGUN_DRAFT_PROMPT_VERSION at it. A version that draft runs refer to is
 * not edited afterwards, or the record of what produced a draft is lost.
 *
 * A template receives the request as plain data (DraftRequest::toArray())
 * and has no model to reach through. Templates print with {!! !!}: the text
 * goes to a model, not to a browser, and must not be HTML-escaped.
 *
 * Example posts and the list of posts planned alongside are the one
 * exception to "as written". They are whole texts and first lines of posts,
 * placed inside an element of the prompt, so anything in them that could
 * open or close an element is made harmless first (asData()): a text can
 * say what it likes, but it cannot leave its element. The brief, the
 * pillar and the idea are printed as the author wrote them.
 */
class PromptRenderer
{
    public const NAMESPACE = 'prompts';

    private const PROMPT = 'draft';

    public function __construct(private readonly Factory $views) {}

    /**
     * @throws UnknownPromptVersion
     */
    public function render(DraftRequest $request, string $version): RenderedPrompt
    {
        $views = $this->viewsFor($version);
        $data = $request->toArray();

        $data['examples'] = array_map(fn (array $example): array => [
            ...$example,
            'body' => self::asData($example['body']),
            'pillar' => $example['pillar'] !== null ? self::asAttribute($example['pillar']) : null,
        ], $data['examples']);

        $data['also_planned'] = array_map(fn (array $post): array => array_map(
            fn (?string $part): ?string => $part !== null ? self::asAttribute($part) : null,
            $post,
        ), $data['also_planned']);

        $user = self::tidy($this->views->make($views['user'], $data)->render());

        return new RenderedPrompt(
            system: self::tidy($this->views->make($views['system'], $data)->render()),
            user: $user,
            version: $version,
            marksPerformance: $this->tellsWhichDidWell($views['user'], $data, $user),
        );
    }

    /**
     * Whether the template made anything of the examples that did well:
     * whether the same request without that knowledge renders to another
     * text. It asks the template and not the name of the version or the
     * words of the prompt, so a version from before the mark answers no,
     * and nothing a brief or an example says can answer yes.
     *
     * @param  view-string  $view
     * @param  array<string, mixed>  $data
     */
    private function tellsWhichDidWell(string $view, array $data, string $rendered): bool
    {
        $examples = is_array($data['examples'] ?? null) ? $data['examples'] : [];

        if (! in_array(true, array_column($examples, 'performed_well'), true)) {
            return false;
        }

        $data['examples'] = array_map(fn (array $example): array => [...$example, 'performed_well' => false], $examples);

        return self::tidy($this->views->make($view, $data)->render()) !== $rendered;
    }

    public function has(string $version): bool
    {
        try {
            $this->viewsFor($version);

            return true;
        } catch (UnknownPromptVersion) {
            return false;
        }
    }

    /**
     * The version string decides the folder and nothing else: it may hold
     * lower case letters, digits, hyphens and underscores, so it can never
     * point outside resources/prompts/draft/.
     *
     * @return array{system: view-string, user: view-string}
     */
    private function viewsFor(string $version): array
    {
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,62}$/', $version) !== 1) {
            throw UnknownPromptVersion::malformed($version);
        }

        return ['system' => $this->view($version, 'system'), 'user' => $this->view($version, 'user')];
    }

    /**
     * @return view-string
     */
    private function view(string $version, string $part): string
    {
        $view = self::NAMESPACE.'::'.self::PROMPT.".{$version}.{$part}";

        if (! $this->views->exists($view)) {
            throw UnknownPromptVersion::missing(self::PROMPT, $version, $part);
        }

        return $view;
    }

    /**
     * Text that is printed inside an element and must stay there. Every
     * "<" that could begin a tag, a closing tag, a comment or a processing
     * instruction is written as "&lt;": one that is followed by a letter,
     * or, with or without space between, by "/", "!" or "?". Nothing else
     * changes, so "1 < 2", "a < b" and "<3" read as they were written. One
     * character becomes four bytes at most.
     */
    public static function asData(string $text): string
    {
        return (string) preg_replace('/<(?=\s*[\/!?]|\p{L})/u', '&lt;', $text);
    }

    /**
     * Text that is printed on one line or inside the quotes of an
     * attribute: on one line, without a double quote, and with every "<"
     * and ">" written as "&lt;" and "&gt;".
     */
    public static function asAttribute(string $text): string
    {
        return str_replace(
            ['"', '<', '>'],
            ["'", '&lt;', '&gt;'],
            trim((string) preg_replace('/\s+/u', ' ', $text)),
        );
    }

    private static function tidy(string $text): string
    {
        return trim((string) preg_replace("/\n{3,}/", "\n\n", str_replace("\r\n", "\n", $text)));
    }
}
