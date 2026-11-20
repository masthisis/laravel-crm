<?php

namespace App\Services\Capture;

use App\Contracts\LeadChannel;
use App\Enums\IntegrationDriver;
use Illuminate\Contracts\Foundation\Application;
use InvalidArgumentException;

/**
 * Resolves the channel adapter for an integration driver. Built-in
 * channels are registered in AppServiceProvider; third parties append
 * their own via extend() from a service provider:
 *
 *     $this->app->afterResolving(CaptureChannelRegistry::class,
 *         fn ($registry) => $registry->extend(SmsChannel::class));
 */
class CaptureChannelRegistry
{
    /** @var array<int, class-string<LeadChannel>> */
    protected array $channels = [];

    public function __construct(
        protected Application $app,
    ) {}

    /** Register a channel implementation (idempotent by class name). */
    public function extend(string $channelClass): static
    {
        if (! is_a($channelClass, LeadChannel::class, true)) {
            throw new InvalidArgumentException("{$channelClass} must implement LeadChannel.");
        }

        $this->channels = collect($this->channels)
            ->reject(fn (string $existing) => $existing === $channelClass)
            ->push($channelClass)
            ->values()
            ->all();

        return $this;
    }

    public function for(IntegrationDriver $driver): LeadChannel
    {
        foreach ($this->channels as $channelClass) {
            $channel = $this->app->make($channelClass);

            if ($channel->driver() === $driver) {
                return $channel;
            }
        }

        // Unregistered drivers fall back to the generic webhook shape.
        return $this->app->make(Channels\WebhookChannel::class);
    }

    /** @return array<int, class-string<LeadChannel>> */
    public function registered(): array
    {
        return $this->channels;
    }
}
