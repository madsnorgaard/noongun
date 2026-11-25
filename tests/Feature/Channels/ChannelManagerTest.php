<?php

use App\Domain\Channels\ChannelManager;
use App\Domain\Channels\Contracts\ChannelAdapter;
use App\Domain\Channels\Exceptions\ChannelAdapterNotAvailable;
use App\Domain\Channels\Models\Channel;
use App\Domain\Workspaces\Models\Workspace;
use Tests\Fakes\ConfiguredFakeAdapter;
use Tests\Fakes\FakeChannelAdapter;

beforeEach(function () {
    config()->set('noongun.channels', [
        'fake' => [
            'adapter' => ConfiguredFakeAdapter::class,
            'api_version' => 'v9.0',
            'scopes' => ['read', 'write'],
        ],
        'missing' => [
            'adapter' => 'App\\Domain\\Channels\\Adapters\\Missing\\MissingAdapter',
        ],
        'wrong' => [
            'adapter' => stdClass::class,
        ],
        'renamed' => [
            'adapter' => ConfiguredFakeAdapter::class,
        ],
        'empty' => [],
    ]);

    $this->manager = app(ChannelManager::class);
});

it('is a singleton', function () {
    expect(app(ChannelManager::class))->toBe($this->manager);
});

it('resolves the configured adapter for a platform and passes it its settings', function () {
    $adapter = $this->manager->adapter('fake');

    expect($adapter)->toBeInstanceOf(ChannelAdapter::class)
        ->toBeInstanceOf(ConfiguredFakeAdapter::class)
        ->and($adapter->platform())->toBe('fake')
        ->and($adapter->settings)->toBe(['api_version' => 'v9.0', 'scopes' => ['read', 'write']])
        ->and($this->manager->settings('fake'))->toBe(['api_version' => 'v9.0', 'scopes' => ['read', 'write']]);
});

it('gives the same adapter instance each time', function () {
    expect($this->manager->adapter('fake'))->toBe($this->manager->adapter('fake'));
});

it('resolves the adapter for a channel from its platform', function () {
    $channel = inWorkspace(Workspace::factory()->create(), fn () => Channel::factory()->platform('fake')->create());

    expect($this->manager->for($channel))->toBe($this->manager->adapter('fake'));
});

it('fails clearly for a platform that is not configured', function (string $platform) {
    expect(fn () => $this->manager->adapter($platform))
        ->toThrow(ChannelAdapterNotAvailable::class, "No channel adapter is configured for platform [{$platform}]");
})->with(['mastodon', 'empty']);

it('fails clearly, and only when asked, for an adapter class that does not exist', function () {
    expect($this->manager->platforms())->toContain('missing')
        ->and(fn () => $this->manager->adapter('missing'))
        ->toThrow(ChannelAdapterNotAvailable::class, 'that class does not exist');
});

it('refuses a class that is not a channel adapter', function () {
    expect(fn () => $this->manager->adapter('wrong'))
        ->toThrow(ChannelAdapterNotAvailable::class, 'does not implement ChannelAdapter');
});

it('refuses an adapter registered under another platform name than its own', function () {
    expect(fn () => $this->manager->adapter('renamed'))
        ->toThrow(ChannelAdapterNotAvailable::class, 'reports platform [fake]');
});

it('lists configured platforms and those that can be used now', function () {
    expect($this->manager->platforms())->toBe(['fake', 'missing', 'wrong', 'renamed', 'empty'])
        ->and($this->manager->available())->toBe(['fake'])
        ->and($this->manager->has('fake'))->toBeTrue()
        ->and($this->manager->has('missing'))->toBeFalse()
        ->and($this->manager->has('mastodon'))->toBeFalse();
});

it('can be extended at runtime, replacing a configured adapter', function () {
    $before = $this->manager->adapter('fake');
    $fake = new FakeChannelAdapter('fake');

    $this->manager->extend('fake', function (array $settings) use ($fake) {
        expect($settings['api_version'])->toBe('v9.0');

        return $fake;
    });
    $this->manager->extend('extra', fn () => new FakeChannelAdapter('extra'));

    expect($this->manager->adapter('fake'))->toBe($fake)->not->toBe($before)
        ->and($this->manager->adapter('extra')->platform())->toBe('extra')
        ->and($this->manager->platforms())->toContain('extra');
});

it('swaps in a fake through the test helper', function () {
    $fake = fakeChannelAdapter('missing');

    expect(app(ChannelManager::class)->adapter('missing'))->toBe($fake);
});

describe('shipped configuration', function () {
    beforeEach(function () {
        $this->shipped = require config_path('noongun.php');
        config()->set('noongun.channels', $this->shipped['channels']);
    });

    it('registers facebook with its adapter class, API version and scopes', function () {
        $facebook = $this->shipped['channels']['facebook'];

        expect($facebook['adapter'])->toBe('App\\Domain\\Channels\\Adapters\\Facebook\\FacebookAdapter')
            ->and($facebook['api_version'])->toMatch('/^v\d+\.\d+$/')
            ->and($facebook['scopes'])->toBe(['pages_show_list', 'pages_read_engagement', 'pages_manage_posts'])
            ->and($facebook)->toHaveKeys(['app_id', 'app_secret', 'graph_url', 'dialog_url']);
    });

    it('boots and lists facebook and linkedin, facebook whether or not its adapter class exists yet', function () {
        $manager = app(ChannelManager::class)->forgetAdapters();

        expect($manager->platforms())->toBe(['facebook', 'linkedin'])
            ->and($manager->adapter('linkedin')->platform())->toBe('linkedin');

        if (class_exists($this->shipped['channels']['facebook']['adapter'])) {
            expect($manager->adapter('facebook')->platform())->toBe('facebook');
        } else {
            expect(fn () => $manager->adapter('facebook'))->toThrow(ChannelAdapterNotAvailable::class);
        }
    });

    it('has publishing settings with a lock that outlives one platform call', function () {
        $publishing = $this->shipped['publishing'];

        expect($publishing['max_attempts'])->toBeGreaterThan(0)
            ->and($publishing['backoff'])->each->toBeInt()
            ->and($publishing['lock_seconds'])->toBeGreaterThan($publishing['timeout'])
            ->and($publishing['queue'])->toBeString();
    });
});
