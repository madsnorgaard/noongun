<?php

use App\Domain\Channels\ChannelManager;
use Tests\Fakes\FakeChannelAdapter;

/**
 * Replaces the adapter for a platform with a FakeChannelAdapter and returns
 * it, so a test can configure results and inspect calls. The default
 * platform matches Channel::factory().
 */
function fakeChannelAdapter(string $platform = 'facebook'): FakeChannelAdapter
{
    $fake = new FakeChannelAdapter($platform);

    app(ChannelManager::class)->extend($platform, fn () => $fake);

    return $fake;
}
