<?php

namespace App\Domain\Channels;

use App\Domain\Channels\Contracts\ChannelAdapter;
use App\Domain\Channels\Exceptions\ChannelAdapterNotAvailable;
use App\Domain\Channels\Models\Channel;
use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;

/**
 * Gives the ChannelAdapter for a platform name, in the style of Laravel's
 * manager classes. Platforms come from config('noongun.channels'):
 *
 *     'facebook' => ['adapter' => FacebookAdapter::class, 'api_version' => ...]
 *
 * Adding a platform means one adapter folder and one config entry. Nothing is
 * resolved until an adapter is asked for, so a configured class that does not
 * exist fails with a clear exception at that point, not at boot.
 */
class ChannelManager
{
    /** @var array<string, ChannelAdapter> */
    private array $adapters = [];

    /** @var array<string, Closure(array<string, mixed>, Container): ChannelAdapter> */
    private array $customCreators = [];

    public function __construct(
        private readonly Container $container,
        private readonly Repository $config,
    ) {}

    /**
     * @throws ChannelAdapterNotAvailable
     */
    public function adapter(string $platform): ChannelAdapter
    {
        return $this->adapters[$platform] ??= $this->resolve($platform);
    }

    /**
     * @throws ChannelAdapterNotAvailable
     */
    public function for(Channel $channel): ChannelAdapter
    {
        return $this->adapter($channel->platform);
    }

    /**
     * Every platform name that is configured or registered with extend().
     *
     * @return list<string>
     */
    public function platforms(): array
    {
        return array_values(array_unique([
            ...array_map(strval(...), array_keys($this->configured())),
            ...array_keys($this->customCreators),
        ]));
    }

    /**
     * The platforms whose adapter can be built right now.
     *
     * @return list<string>
     */
    public function available(): array
    {
        return array_values(array_filter($this->platforms(), $this->has(...)));
    }

    public function has(string $platform): bool
    {
        try {
            $this->adapter($platform);

            return true;
        } catch (ChannelAdapterNotAvailable) {
            return false;
        }
    }

    /**
     * The platform's config entry without the adapter class: API version,
     * scopes, credentials and whatever else the adapter reads.
     *
     * @return array<string, mixed>
     */
    public function settings(string $platform): array
    {
        $entry = $this->configured()[$platform] ?? [];
        unset($entry['adapter']);

        return $entry;
    }

    /**
     * Register or replace a platform at runtime. The creator receives the
     * platform's settings and the container. Tests use this to swap in a fake.
     *
     * @param  Closure(array<string, mixed>, Container): ChannelAdapter  $creator
     */
    public function extend(string $platform, Closure $creator): static
    {
        $this->customCreators[$platform] = $creator;
        unset($this->adapters[$platform]);

        return $this;
    }

    /**
     * Drop resolved adapters, so the next call builds them again.
     */
    public function forgetAdapters(): static
    {
        $this->adapters = [];

        return $this;
    }

    private function resolve(string $platform): ChannelAdapter
    {
        $settings = $this->settings($platform);

        if (isset($this->customCreators[$platform])) {
            return $this->verified($platform, $this->customCreators[$platform]($settings, $this->container));
        }

        $class = $this->configured()[$platform]['adapter'] ?? null;

        if (! is_string($class) || $class === '') {
            throw ChannelAdapterNotAvailable::notConfigured($platform);
        }

        if (! class_exists($class)) {
            throw ChannelAdapterNotAvailable::classMissing($platform, $class);
        }

        if (! is_subclass_of($class, ChannelAdapter::class)) {
            throw ChannelAdapterNotAvailable::notAnAdapter($platform, $class);
        }

        return $this->verified($platform, $this->container->make($class, ['settings' => $settings]));
    }

    private function verified(string $platform, ChannelAdapter $adapter): ChannelAdapter
    {
        if ($adapter->platform() !== $platform) {
            throw ChannelAdapterNotAvailable::platformMismatch($platform, $adapter::class, $adapter->platform());
        }

        return $adapter;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function configured(): array
    {
        $platforms = $this->config->get('noongun.channels', []);

        return is_array($platforms) ? array_filter($platforms, is_array(...)) : [];
    }
}
