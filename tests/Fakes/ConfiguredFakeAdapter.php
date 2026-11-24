<?php

namespace Tests\Fakes;

/**
 * A fake that is built the way real adapters are: by ChannelManager, through
 * the container, with its settings from config. Reports platform "fake".
 */
class ConfiguredFakeAdapter extends FakeChannelAdapter
{
    /**
     * @param  array<string, mixed>  $settings
     */
    public function __construct(public readonly array $settings)
    {
        parent::__construct('fake');
    }
}
