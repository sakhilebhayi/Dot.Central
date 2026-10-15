<?php

namespace App\Models;

/**
 * Typed access to a room's settings with config defaults. Values are
 * stored as JSON so numbers, booleans and strings all keep their type.
 */
final class RoomSettings
{
    /** @var array<string, mixed>|null */
    private ?array $loaded = null;

    public function __construct(private readonly ControlRoom $room) {}

    public function get(string $key, mixed $default = null): mixed
    {
        $this->load();

        if (array_key_exists($key, $this->loaded)) {
            return $this->loaded[$key];
        }

        return config('mines.room_defaults.'.$key, $default);
    }

    public function set(string $key, mixed $value): void
    {
        ControlRoomSetting::updateOrCreate(['control_room_id' => $this->room->id, 'key' => $key], ['value' => $value]);
        $this->loaded = null;
    }

    /** @return array<string, mixed> every default, overlaid with stored values */
    public function all(): array
    {
        $this->load();

        return array_merge((array) config('mines.room_defaults', []), $this->loaded);
    }

    private function load(): void
    {
        if ($this->loaded === null) {
            $this->loaded = ControlRoomSetting::where('control_room_id', $this->room->id)->pluck('value', 'key')
                ->map(fn ($value) => is_array($value) && array_keys($value) === [0] ? $value[0] : $value)
                ->all();
        }
    }
}
