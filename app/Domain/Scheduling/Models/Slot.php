<?php

namespace App\Domain\Scheduling\Models;

use App\Domain\Channels\Models\Channel;
use App\Domain\Workspaces\Concerns\BelongsToWorkspace;
use App\Domain\Workspaces\Exceptions\MissingWorkspaceContext;
use App\Domain\Workspaces\Models\Workspace;
use App\Domain\Workspaces\Policies\ContentPolicy;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\SlotFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

/**
 * A preferred posting time for one channel: a weekday and a time of day.
 *
 * weekday is the ISO number, 1 for Monday to 7 for Sunday. time is a
 * wall-clock time in the workspace timezone, "09:00", and is the one time
 * in the application that is not stored in UTC: a slot is a habit ("Tuesdays
 * at nine"), not an instant, and it must stay at nine when the clocks
 * change (ADR 0011). occurrenceInWeekOf() and nextOccurrence() turn it into the UTC
 * instant that goes into posts.scheduled_at, through Workspace::toUtc(), so
 * a time the clocks skip or repeat is handled as described in ADR 0010.
 *
 * A slot only proposes a time for a draft. It never schedules anything.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $channel_id
 * @property int $weekday
 * @property string $time H:i, local to the workspace
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Channel|null $channel
 * @property-read Workspace|null $workspace
 */
#[Fillable(['channel_id', 'weekday', 'time'])]
#[UseFactory(SlotFactory::class)]
#[UsePolicy(ContentPolicy::class)]
class Slot extends Model
{
    use BelongsToWorkspace;

    /** @use HasFactory<SlotFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
        ];
    }

    /**
     * Read as "09:00" whatever the database returns, and refused on write
     * unless it is a time of day in hours and minutes.
     *
     * @return Attribute<string, string>
     */
    protected function time(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value): string => substr((string) (is_scalar($value) ? $value : ''), 0, 5),
            set: function (mixed $value): string {
                if (! is_string($value) || preg_match('/^([01]\d|2[0-3]):[0-5]\d(:00)?$/', $value) !== 1) {
                    throw new InvalidArgumentException('A slot time is a time of day in hours and minutes, for example 09:00.');
                }

                return substr($value, 0, 5);
            },
        );
    }

    /**
     * @return BelongsTo<Channel, $this>
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /**
     * The instant, in UTC, at which this slot falls in the week that
     * contains $instant. Weeks run from Monday to Sunday in the workspace
     * timezone, as on the calendar.
     */
    public function occurrenceInWeekOf(DateTimeInterface $instant): CarbonImmutable
    {
        $workspace = $this->workspace ?? throw new MissingWorkspaceContext;

        $day = $workspace->toLocal(CarbonImmutable::instance($instant))
            ->startOfWeek(CarbonInterface::MONDAY)
            ->addDays($this->weekday - 1);

        return $workspace->toUtc($day->format('Y-m-d').' '.$this->time);
    }

    /**
     * The first instant, in UTC, at which this slot falls after $after, or
     * after now.
     */
    public function nextOccurrence(?DateTimeInterface $after = null): CarbonImmutable
    {
        $after = CarbonImmutable::instance($after ?? now());
        $thisWeek = $this->occurrenceInWeekOf($after);

        return $thisWeek->gt($after) ? $thisWeek : $this->occurrenceInWeekOf($after->addWeek());
    }
}
