<?php

namespace App\Services\Lineup;

use App\Models\ControlRoom;
use App\Models\Geofence;
use App\Models\MineArea;
use Illuminate\Validation\ValidationException;

/**
 * Where a face can be: the room's Dot.Mines geofences; when there are
 * none, its Dot.Mines mine areas; when those are not configured either,
 * generic mining names. One list, one source at a time, so the operator
 * never mixes a live geofence with a made-up name.
 *
 * @phpstan-type Location array{key: string, source: 'geofence'|'area'|'default', ref: int|null, name: string, group: string}
 */
final class LocationCatalogue
{
    /** @var list<string> */
    public const DEFAULT_NAMES = [
        'Loading area 1', 'Loading area 2', 'Loading area 3', 'Loading area 4',
        'Dumping area 1', 'Dumping area 2', 'Dumping area 3',
        'Stockpile 1', 'Stockpile 2',
        'ROM pad', 'Crusher feed', 'Waste dump 1', 'Waste dump 2',
        'Haul road 1', 'Haul road 2', 'Pit ramp 1', 'Pit ramp 2',
        'Workshop', 'Fuel bay', 'Water point',
    ];

    /**
     * @return array{source: 'geofence'|'area'|'default', options: list<Location>}
     */
    public function options(ControlRoom $room): array
    {
        $geofences = Geofence::withoutGlobalScopes()->where('control_room_id', $room->id)->whereNull('retired_at')
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'inactive'))->orderBy('type')->orderBy('name')->get();
        if ($geofences->isNotEmpty()) {
            return ['source' => 'geofence', 'options' => $geofences->map(fn (Geofence $g) => ['key' => 'geofence:'.$g->id, 'source' => 'geofence', 'ref' => $g->id, 'name' => $g->name, 'group' => ucfirst((string) ($g->type ?: 'geofence')).'s'])->values()->all()];
        }

        $areas = MineArea::withoutGlobalScopes()->where('control_room_id', $room->id)->orderBy('name')->get();
        if ($areas->isNotEmpty()) {
            return ['source' => 'area', 'options' => $areas->map(fn (MineArea $a) => ['key' => 'area:'.$a->id, 'source' => 'area', 'ref' => $a->id, 'name' => $a->name, 'group' => 'Mine areas'])->values()->all()];
        }

        return ['source' => 'default', 'options' => array_map(fn (string $name) => ['key' => 'default:'.$name, 'source' => 'default', 'ref' => null, 'name' => $name, 'group' => 'Default areas (no geofences or mine areas in Dot.Mines yet)'], self::DEFAULT_NAMES)];
    }

    /**
     * Turn a picked key into stored attributes; a key outside the current list is refused.
     *
     * @return array{location_source: string, location_ref: int|null, location_name: string, mine_area_id: int|null}
     */
    public function resolve(ControlRoom $room, ?string $key): array
    {
        $key = trim((string) $key);
        $catalogue = $this->options($room);
        $match = collect($catalogue['options'])->firstWhere('key', $key);

        if ($match === null) {
            throw ValidationException::withMessages(['lineup' => 'LU-05 Pick where the face is from the list ('.match ($catalogue['source']) {
                'geofence' => 'the Dot.Mines geofences', 'area' => 'the Dot.Mines mine areas', default => 'the default area names'
            }.').']);
        }

        $mineAreaId = match ($match['source']) {
            'area' => $match['ref'],
            'geofence' => Geofence::withoutGlobalScopes()->find($match['ref'])?->mine_area_id !== null ? MineArea::withoutGlobalScopes()->where('control_room_id', $room->id)->where('mines_area_id', Geofence::withoutGlobalScopes()->find($match['ref'])->mine_area_id)->value('id') : null,
            default => null,
        };

        return ['location_source' => $match['source'], 'location_ref' => $match['ref'], 'location_name' => $match['name'], 'mine_area_id' => $mineAreaId];
    }
}
