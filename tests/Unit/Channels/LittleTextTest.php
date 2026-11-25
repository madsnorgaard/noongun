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

it('keeps a hashtag and escapes a number sign that is not one', function (string $typed, string $sent) {
    expect(LittleText::escape($typed))->toBe($sent);
})->with([
    'a hashtag' => ['Fresh #coffee today', 'Fresh #coffee today'],
    'a hashtag with an underscore' => ['#cape_town', '#cape_town'],
    'a hashtag in another alphabet' => ['#kaffeæøå', '#kaffeæøå'],
    'a hashtag in brackets' => ['(#coffee)', '\\(#coffee\\)'],
    'a lone number sign' => ['C# and F #', 'C\\# and F \\#'],
    'an underscore outside a hashtag' => ['snake_case', 'snake\\_case'],
]);

it('turns a typed mention into plain text, so nobody is mentioned by a post', function () {
    expect(LittleText::escape('Hello @[Devtestco](urn:li:organization:2414183)'))
        ->toBe('Hello \\@\\[Devtestco\\]\\(urn:li:organization:2414183\\)');
});

it('leaves no way to open a template', function () {
    expect(LittleText::escape('{hashtag|#|x}'))->toBe('\\{hashtag\\|\\#\\|x\\}');
});
