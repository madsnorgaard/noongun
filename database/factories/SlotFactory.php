<?php

namespace Database\Factories;

use App\Domain\Channels\Models\Channel;
use App\Domain\Scheduling\Models\Slot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Create inside a workspace: inWorkspace($workspace, fn () => Slot::factory()->create()).
 * Without ->for($channel) it makes a new channel. Mondays at 09:00 unless
 * told otherwise.
 *
 * @extends Factory<Slot>
 */
class SlotFactory extends Factory
{
    protected $model = Slot::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'channel_id' => Channel::factory(),
            'weekday' => 1,
            'time' => '09:00',
        ];
    }

    /**
     * @param  int  $weekday  ISO weekday, 1 for Monday to 7 for Sunday
     * @param  string  $time  wall-clock time in the workspace timezone, H:i
     */
    public function at(int $weekday, string $time): static
    {
        return $this->state(fn () => ['weekday' => $weekday, 'time' => $time]);
    }
}
