{{-- Prompt version v1, user part. Receives DraftRequest::toArray() and nothing else. Print with {!! !!} only. --}}
<brief title="{!! $brief['title'] !!}">
{!! $brief['body'] !!}
</brief>
@if ($brief['calls_to_action'] !== [])

<calls_to_action>
@foreach ($brief['calls_to_action'] as $call)
- {!! $call['text'] !!}@if ($call['url'] !== null) ({!! $call['url'] !!})@endif

@endforeach
</calls_to_action>
@endif
@if ($voice_rules['general'] !== [])

<voice_rules>
@foreach ($voice_rules['general'] as $rule)
- {!! $rule !!}
@endforeach
</voice_rules>
@endif
@if ($pillar !== null)

<pillar name="{!! $pillar['name'] !!}">
{!! $pillar['description'] ?? '' !!}
</pillar>
@endif
@if ($idea !== null)

<idea title="{!! $idea['title'] !!}">
{!! $idea['notes'] ?? '' !!}
</idea>
@endif
@if ($source_url !== null)

<source_url>{!! $source_url !!}</source_url>
@endif

<target_channels>
@foreach ($channels as $channel)
<channel channel_id="{!! $channel['channel_id'] !!}" platform="{!! $channel['platform'] !!}" max_characters="{!! $channel['max_body_length'] !!}" link_previews="{!! $channel['link_previews'] ? 'yes' : 'no' !!}">
@foreach ($channel['voice_rules'] as $rule)
- {!! $rule !!}
@endforeach
</channel>
@endforeach
</target_channels>
@if ($examples !== [])

<example_posts>
@foreach ($examples as $example)
<example platform="{!! $example['platform'] !!}">
{!! $example['body'] !!}
</example>
@endforeach
</example_posts>
@endif

Write the post for the target channels above.
