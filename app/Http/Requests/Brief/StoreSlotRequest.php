<?php

namespace App\Http\Requests\Brief;

use App\Domain\Channels\ChannelStatus;
use App\Domain\Scheduling\Models\Slot;
use App\Domain\Workspaces\CurrentWorkspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One time of day on one or more weekdays for one channel. The time is on
 * the clock of the workspace and is stored as it is given (ADR 0011). What
 * happens to a time the clocks skip or repeat is in ADR 0010.
 */
class StoreSlotRequest extends FormRequest
{
    /** Channels in these conditions are not offered and not accepted. */
    public const NOT_OFFERED = [ChannelStatus::Disconnected, ChannelStatus::Pending];

    public function authorize(): bool
    {
        return $this->user()?->can('create', Slot::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'channel_id' => [
                'required',
                'integer',
                Rule::exists('channels', 'id')
                    ->where('workspace_id', app(CurrentWorkspace::class)->id())
                    ->whereNotIn('status', array_map(fn (ChannelStatus $status): string => $status->value, self::NOT_OFFERED)),
            ],
            'weekdays' => ['required', 'array', 'list', 'min:1', 'max:7'],
            'weekdays.*' => ['required', 'integer', 'between:1,7', 'distinct'],
            'time' => ['required', 'string', 'date_format:H:i'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'channel_id.required' => __('Choose a channel.'),
            'channel_id.exists' => __('Choose a channel that is connected in this workspace.'),
            'weekdays.required' => __('Choose at least one day.'),
            'weekdays.*.between' => __('A day is Monday to Sunday.'),
            'time.required' => __('Give a time of day.'),
            'time.date_format' => __('Give the time in hours and minutes, for example 09:00.'),
        ];
    }

    public function channelId(): int
    {
        $id = $this->validated('channel_id');

        return is_numeric($id) ? (int) $id : 0;
    }

    /**
     * @return list<int> ISO weekdays, Monday 1 to Sunday 7, in order
     */
    public function weekdays(): array
    {
        $weekdays = [];

        foreach ((array) $this->validated('weekdays', []) as $weekday) {
            if (is_numeric($weekday)) {
                $weekdays[] = (int) $weekday;
            }
        }

        sort($weekdays);

        return $weekdays;
    }

    public function time(): string
    {
        $time = $this->validated('time');

        return is_string($time) ? $time : '';
    }
}
