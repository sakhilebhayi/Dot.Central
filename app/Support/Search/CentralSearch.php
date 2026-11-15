<?php

namespace App\Support\Search;

use App\Models\Agent;
use App\Models\Alert;
use App\Models\AuditEntry;
use App\Models\ControlRoom;
use App\Models\Equipment;
use App\Models\OperationalException;
use App\Models\Operator;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The header search (R-52). A real search over what Dot.Central holds, scoped the way every list in this app is scoped:
 * to the CONTROL ROOMS the signed-in person may see (`ControlRoom::accessibleBy`, which is the current team, narrowed to
 * assigned rooms for operators and supervisors) and to the current team for agents. Nothing is invented: every row is a
 * stored record with a page that already exists.
 *
 *  - control rooms, equipment, alerts, exceptions, shifts: the person's rooms
 *  - operators: only rooms the person MANAGES (the operator register is an administrator page; names and employee
 *    numbers are personal data and are not shown to anyone who could not open that page)
 *  - agents: the current team
 *  - "Go to": the sidebar's own pages, minus the ones this person may not open (Audit Log, Health)
 *
 * The query is parameterised, lower-cased on both sides (so SQLite and PostgreSQL agree), every word of the query must
 * match somewhere in the row, and each group is capped. The caller decides the cap: a few rows for the dropdown, more
 * for the full results page.
 */
class CentralSearch
{
    public const MIN_LENGTH = 2;

    public const MAX_LENGTH = 80;

    /** @return list<string> the words of the query, with LIKE wildcards and control characters removed */
    public static function words(string $term): array
    {
        $term = mb_strtolower(mb_substr(trim($term), 0, self::MAX_LENGTH));
        $term = preg_replace('/[%_\\\\\p{C}]+/u', ' ', $term) ?? '';
        $words = array_values(array_filter(preg_split('/\s+/u', $term) ?: [], fn (string $w): bool => $w !== ''));

        return array_slice($words, 0, 5);
    }

    public static function isSearchable(string $term): bool
    {
        return mb_strlen(trim($term)) >= self::MIN_LENGTH && self::words($term) !== [];
    }

    /**
     * @return array<string, list<SearchHit>> group label => hits, empty groups left out
     */
    public function search(User $user, string $term, int $perGroup = 4): array
    {
        if (! self::isSearchable($term) || $user->currentTeam === null) {
            return [];
        }

        $words = self::words($term);
        $rooms = ControlRoom::accessibleBy($user)->orderBy('name')->get(['id', 'name', 'slug', 'team_id']);
        $roomIds = $rooms->pluck('id')->all();
        $roomNames = $rooms->pluck('name', 'id');
        $managed = $rooms->filter(fn (ControlRoom $room): bool => $user->can('manage', $room))->pluck('id')->all();

        $groups = [
            'Go to' => $this->pages($user, $words, $perGroup),
            'Control rooms' => $this->rooms($rooms, $words, $perGroup),
            'Equipment' => $this->equipment($roomIds, $roomNames, $words, $perGroup),
            'Alerts' => $this->alerts($roomIds, $roomNames, $words, $perGroup),
            'Exceptions' => $this->exceptions($roomIds, $roomNames, $words, $perGroup),
            'Shifts' => $this->shifts($roomIds, $roomNames, $words, $perGroup),
            'Operators' => $this->operators($managed, $roomNames, $words, $perGroup),
            'Agents' => $this->agents($user, $words, $perGroup),
        ];

        return array_filter($groups, fn (array $hits): bool => $hits !== []);
    }

    /**
     * The pages a person can go to, for the empty box and for a typed match.
     *
     * @param  list<string>|null  $words  null: all of them (the empty box)
     * @return list<SearchHit>
     */
    public function pages(User $user, ?array $words = null, int $limit = 8): array
    {
        $pages = [
            ['Overview', 'dashboard', 'home dashboard room state'],
            ['Control rooms', 'control-rooms.index', 'rooms sites mine'],
            ['Alerts', 'alerts.index', 'alarms warnings'],
            ['Exceptions', 'exceptions.index', 'rules issues'],
            ['Approvals', 'approvals.index', 'decisions sign off'],
            ['Data quality', 'quality.index', 'validation'],
            ['Reports', 'reports.index', 'export csv'],
            ['Agents', 'agents.hub', 'ai assistants'],
            ['Notifications', 'notifications.index', 'inbox bell'],
        ];
        if (Gate::forUser($user)->allows('viewAny', AuditEntry::class)) {
            $pages[] = ['Audit log', 'audit.index', 'history trail'];
        }
        if (Gate::forUser($user)->allows('view-health')) {
            $pages[] = ['Health', 'health.index', 'integration status sync'];
        }

        $hits = [];
        foreach ($pages as [$label, $route, $keywords]) {
            $haystack = mb_strtolower($label.' '.$keywords);
            if ($words !== null && collect($words)->contains(fn (string $w): bool => ! str_contains($haystack, $w))) {
                continue;
            }
            $hits[] = new SearchHit('Go to', $label, null, route($route), null);
        }

        return array_slice($hits, 0, $limit);
    }

    /**
     * @param  Collection<int, ControlRoom>  $rooms
     * @param  list<string>  $words
     * @return list<SearchHit>
     */
    private function rooms($rooms, array $words, int $limit): array
    {
        return $rooms
            ->filter(fn (ControlRoom $room): bool => $this->matches(mb_strtolower($room->name.' '.$room->slug), $words))
            ->take($limit)
            ->map(fn (ControlRoom $room): SearchHit => new SearchHit('Control rooms', $room->name, null, route('control-rooms.show', $room), null))
            ->values()->all();
    }

    /**
     * @param  list<int>  $roomIds
     * @param  Collection<int, string>  $roomNames
     * @param  list<string>  $words
     * @return list<SearchHit>
     */
    private function equipment(array $roomIds, $roomNames, array $words, int $limit): array
    {
        if ($roomIds === []) {
            return [];
        }

        return $this->like(Equipment::query()->whereIn('control_room_id', $roomIds), ['name', 'machine_type', 'model', 'manufacturer', 'serial_number', 'registration_number', 'mine_area_name'], $words)
            ->orderBy('name')->limit($limit)->get()
            ->map(fn (Equipment $e): SearchHit => new SearchHit(
                'Equipment',
                $e->name,
                $this->sub([$e->machine_type, $e->model ?? $e->manufacturer, $roomNames[$e->control_room_id] ?? null]),
                route('control-rooms.equipment.show', [$e->control_room_id, $e->id]),
                $e->serial_number ?: $e->registration_number,
                $e->machine_type ? ucfirst(str_replace('_', ' ', $e->machine_type)) : null,
            ))->all();
    }

    /**
     * @param  list<int>  $roomIds
     * @param  Collection<int, string>  $roomNames
     * @param  list<string>  $words
     * @return list<SearchHit>
     */
    private function alerts(array $roomIds, $roomNames, array $words, int $limit): array
    {
        if ($roomIds === []) {
            return [];
        }

        return $this->like(Alert::query()->whereIn('control_room_id', $roomIds), ['title', 'description', 'source'], $words)
            ->orderByDesc('triggered_at')->limit($limit)->get()
            ->map(fn (Alert $a): SearchHit => new SearchHit(
                'Alerts',
                $a->title,
                $this->sub([ucfirst((string) $a->severity), $a->cleared_at ? 'resolved' : 'open', $roomNames[$a->control_room_id] ?? null]),
                route('alerts.index', ['room' => $a->control_room_id, 'state' => $a->cleared_at ? 'resolved' : 'open']),
                null,
                ucfirst((string) $a->severity),
            ))->all();
    }

    /**
     * @param  list<int>  $roomIds
     * @param  Collection<int, string>  $roomNames
     * @param  list<string>  $words
     * @return list<SearchHit>
     */
    private function exceptions(array $roomIds, $roomNames, array $words, int $limit): array
    {
        if ($roomIds === []) {
            return [];
        }

        return $this->like(OperationalException::query()->whereIn('control_room_id', $roomIds), ['title', 'message', 'rule_code', 'group'], $words)
            ->orderByDesc('raised_at')->limit($limit)->get()
            ->map(fn (OperationalException $x): SearchHit => new SearchHit(
                'Exceptions',
                $x->title,
                $this->sub([ucfirst(strtolower((string) $x->severity)), strtolower((string) $x->status), $roomNames[$x->control_room_id] ?? null]),
                route('exceptions.index', ['room' => $x->control_room_id, 'status' => $x->status, 'sev' => 'ALL']),
                $x->rule_code,
                ucfirst(strtolower((string) $x->severity)),
            ))->all();
    }

    /**
     * @param  list<int>  $roomIds
     * @param  Collection<int, string>  $roomNames
     * @param  list<string>  $words
     * @return list<SearchHit>
     */
    private function shifts(array $roomIds, $roomNames, array $words, int $limit): array
    {
        if ($roomIds === []) {
            return [];
        }

        $query = Shift::query()->whereIn('shifts.control_room_id', $roomIds)->with('shiftType');
        foreach ($words as $word) {
            $like = '%'.$word.'%';
            $query->where(function (Builder $q) use ($like): void {
                $q->whereRaw('LOWER('.$this->wrap('shifts.status').') LIKE ?', [$like])
                    ->orWhereRaw('LOWER(CAST('.$this->wrap('shifts.shift_date').' AS '.(DB::connection()->getDriverName() === 'mysql' ? 'CHAR' : 'TEXT').')) LIKE ?', [$like])
                    ->orWhereHas('shiftType', fn (Builder $t) => $t->whereRaw('LOWER('.$this->wrap('shift_types.name').') LIKE ?', [$like]));
            });
        }

        return $query->orderByDesc('shifts.starts_at')->limit($limit)->get()
            ->map(fn (Shift $s): SearchHit => new SearchHit(
                'Shifts',
                $s->label(),
                $this->sub([ucfirst(strtolower((string) $s->status)), $roomNames[$s->control_room_id] ?? null]),
                route('shifts.show', $s),
                null,
                ucfirst(strtolower((string) $s->status)),
            ))->all();
    }

    /**
     * @param  list<int>  $managedRoomIds
     * @param  Collection<int, string>  $roomNames
     * @param  list<string>  $words
     * @return list<SearchHit>
     */
    private function operators(array $managedRoomIds, $roomNames, array $words, int $limit): array
    {
        if ($managedRoomIds === []) {
            return [];
        }

        return $this->like(Operator::query()->whereIn('control_room_id', $managedRoomIds), ['first_name', 'last_name', 'preferred_name', 'employee_number', 'tag_id'], $words)
            ->orderBy('last_name')->orderBy('first_name')->limit($limit)->get()
            ->map(fn (Operator $o): SearchHit => new SearchHit(
                'Operators',
                $o->name(),
                $this->sub([$o->is_active ? null : 'inactive', $roomNames[$o->control_room_id] ?? null]),
                route('control-rooms.admin', $o->control_room_id),
                $o->employee_number,
            ))->all();
    }

    /**
     * @param  list<string>  $words
     * @return list<SearchHit>
     */
    private function agents(User $user, array $words, int $limit): array
    {
        $team = $user->currentTeam;
        if ($team === null) {
            return [];
        }

        return $this->like(Agent::query()->where('agents.team_id', $team->getKey()), ['name', 'description'], $words)
            ->orderBy('name')->limit($limit)->get()
            ->map(fn (Agent $a): SearchHit => new SearchHit('Agents', $a->name, $this->sub([$a->is_active ? null : 'inactive']), route('agents.show', $a), null))
            ->all();
    }

    /**
     * Every word must appear in at least one of the columns.
     *
     * @param  Builder<*>  $query
     * @param  list<string>  $columns
     * @param  list<string>  $words
     * @return Builder<*>
     */
    private function like(Builder $query, array $columns, array $words): Builder
    {
        $table = $query->getModel()->getTable();
        foreach ($words as $word) {
            $like = '%'.$word.'%';
            $query->where(function (Builder $q) use ($columns, $like, $table): void {
                foreach ($columns as $column) {
                    $q->orWhereRaw('LOWER('.$this->wrap($table.'.'.$column).') LIKE ?', [$like]);
                }
            });
        }

        return $query;
    }

    private function wrap(string $identifier): string
    {
        return DB::connection()->getQueryGrammar()->wrap($identifier);
    }

    /** @param  list<string>  $words */
    private function matches(string $haystack, array $words): bool
    {
        foreach ($words as $word) {
            if (! str_contains($haystack, $word)) {
                return false;
            }
        }

        return true;
    }

    /** @param  list<string|null>  $parts */
    private function sub(array $parts): ?string
    {
        $parts = array_values(array_filter($parts, fn ($p): bool => $p !== null && $p !== ''));

        return $parts === [] ? null : implode(' · ', $parts);
    }
}
