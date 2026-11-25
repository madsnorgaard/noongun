<?php

use App\Domain\Channels\Adapters\LinkedIn\LittleText;

it('escapes every character the commentary format reserves', function () {
    expect(LittleText::escape('| { } @ [ ] ( ) < > \\ * _ ~'))
        ->toBe('\\| \\{ \\} \\@ \\[ \\] \\( \\) \\< \\> \\\\ \\* \\_ \\~');
});

it('leaves plain text, line breaks and other alphabets as they are', function () {
    $text = "Smørrebrød kl. 12 - 100% rug.\n\nKom forbi! Ngiyabonga. 你好";

    expect(LittleText::escape($text))->toBe($text);
});

it('keeps a hashtag only at the start of a word, made of letters and digits', function (string $typed, string $sent) {
    expect(LittleText::escape($typed))->toBe($sent);
})->with([
    'a hashtag after a space' => ['Fresh #coffee today', 'Fresh #coffee today'],
    'a hashtag at the start' => ['#coffee is here', '#coffee is here'],
    'a hashtag after a line break' => ["Today\n#coffee", "Today\n#coffee"],
    'a hashtag before punctuation' => ['Try #coffee, #tea.', 'Try #coffee, #tea.'],
    'a hashtag in another alphabet' => ['#kaffeæøå og #咖啡', '#kaffeæøå og #咖啡'],
    'a hashtag with digits' => ['#cop31', '#cop31'],
    // The underscore is reserved too, so such a tag is sent as plain text.
    'a tag with an underscore' => ['#cape_town', '\\#cape\\_town'],
    'a tag that ends in an underscore' => ['#cape_', '\\#cape\\_'],
    'a tag in brackets' => ['(#coffee)', '\\(\\#coffee\\)'],
    'the fragment of an address' => ['https://x.example/page#section', 'https://x.example/page\\#section'],
    'a fragment straight after the path' => ['https://x.example/#top', 'https://x.example/\\#top'],
    'a number sign inside a word' => ['C#minor and F#', 'C\\#minor and F\\#'],
    'a lone number sign' => ['# and #', '\\# and \\#'],
    'two number signs' => ['##coffee', '\\#\\#coffee'],
    'an underscore outside a hashtag' => ['snake_case', 'snake\\_case'],
]);

it('turns a typed mention into plain text, so nobody is mentioned by a post', function () {
    expect(LittleText::escape('Hello @[Devtestco](urn:li:organization:2414183)'))
        ->toBe('Hello \\@\\[Devtestco\\]\\(urn:li:organization:2414183\\)');
});

it('leaves no way to open a template', function () {
    expect(LittleText::escape('{hashtag|#|x}'))->toBe('\\{hashtag\\|\\#\\|x\\}');
});

it('refuses text that is not valid UTF-8 instead of returning nothing', function () {
    expect(fn () => LittleText::escape("Fresh \xC3\x28 beans"))->toThrow(InvalidArgumentException::class);
});
