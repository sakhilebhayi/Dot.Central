<?php

namespace App\Http\Controllers;

use App\Models\ControlRoom;
use App\Models\DowntimeReason;
use App\Models\Equipment;
use App\Models\Operator;
use App\Models\ShiftType;
use App\Services\Audits;
use App\Support\RoleClass;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Per-room administration: shift types, stoppage reasons and thresholds.
 * Administrators only; every change is audited with a reason where asked.
 */
class ControlRoomAdminController extends Controller
{
    public const SETTING_KEYS = ['offline_minutes', 'stale_minutes', 'idle_minutes_threshold', 'zero_loads_hours', 'classify_after_minutes', 'unack_minutes', 'engine_hours_budget'];

    public function __construct(private readonly Audits $audits) {}

    public function index(Request $request, ControlRoom $controlRoom): View
    {
        $this->authorize('manage', $controlRoom);

        return view('admin.index', [
            'controlRoom' => $controlRoom,
            'shiftTypes' => $controlRoom->shiftTypes()->get(),
            'reasons' => $controlRoom->downtimeReasons()->get(),
            'settings' => $controlRoom->settings()->all(),
            'settingKeys' => self::SETTING_KEYS,
            'categories' => DowntimeReason::CATEGORIES,
            'operators' => $controlRoom->operators()->orderBy('last_name')->orderBy('first_name')->get(),
            'equipment' => $controlRoom->equipment()->whereNull('retired_at')->orderBy('name')->get(),
            'roleClasses' => RoleClass::LABELS,
        ]);
    }

    public function storeShiftType(Request $request, ControlRoom $controlRoom): RedirectResponse
    {
        $this->authorize('manage', $controlRoom);
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:16', 'alpha_dash', Rule::unique('shift_types', 'code')->where('control_room_id', $controlRoom->id)],
            'name' => ['required', 'string', 'max:64'],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i'],
        ]);
        $controlRoom->shiftTypes()->create($validated + ['crosses_midnight' => $validated['ends_at'] <= $validated['starts_at'], 'sort' => $controlRoom->shiftTypes()->count() + 1]);

        return redirect(route('control-rooms.admin', $controlRoom).'#shifts')->with('status', 'Shift type added.');
    }

    public function toggleShiftType(Request $request, ControlRoom $controlRoom, ShiftType $shiftType): RedirectResponse
    {
        $this->authorize('manage', $controlRoom);
        abort_unless($shiftType->control_room_id === $controlRoom->id, 404);
        $shiftType->update(['is_active' => ! $shiftType->is_active]);

        return redirect(route('control-rooms.admin', $controlRoom).'#shifts')->with('status', $shiftType->name.($shiftType->is_active ? ' activated.' : ' deactivated.'));
    }

    public function storeReason(Request $request, ControlRoom $controlRoom): RedirectResponse
    {
        $this->authorize('manage', $controlRoom);
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:32', 'alpha_dash', Rule::unique('downtime_reasons', 'code')->where('control_room_id', $controlRoom->id)],
            'name' => ['required', 'string', 'max:96'],
            'category' => ['required', Rule::in(DowntimeReason::CATEGORIES)],
            'counts_against_availability' => ['sometimes', 'boolean'],
            'requires_description' => ['sometimes', 'boolean'],
        ]);
        $controlRoom->downtimeReasons()->create($validated + ['counts_against_availability' => $request->boolean('counts_against_availability'), 'requires_description' => $request->boolean('requires_description')]);

        return redirect(route('control-rooms.admin', $controlRoom).'#reasons')->with('status', 'Reason added.');
    }

    public function toggleReason(Request $request, ControlRoom $controlRoom, DowntimeReason $reason): RedirectResponse
    {
        $this->authorize('manage', $controlRoom);
        abort_unless($reason->control_room_id === $controlRoom->id, 404);
        $reason->update(['is_active' => ! $reason->is_active]);

        return redirect(route('control-rooms.admin', $controlRoom).'#reasons')->with('status', $reason->name.($reason->is_active ? ' activated.' : ' deactivated.'));
    }

    public function storeOperator(Request $request, ControlRoom $controlRoom): RedirectResponse
    {
        $this->authorize('manage', $controlRoom);
        $validated = $request->validate([
            'employee_number' => ['required', 'string', 'max:32', Rule::unique('operators', 'employee_number')->where('control_room_id', $controlRoom->id)],
            'first_name' => ['required', 'string', 'max:64'],
            'last_name' => ['required', 'string', 'max:64'],
            'preferred_name' => ['nullable', 'string', 'max:64'],
            'tag_id' => ['nullable', 'string', 'max:64'],
        ]);
        $controlRoom->operators()->create($validated);

        return redirect(route('control-rooms.admin', $controlRoom).'#operators')->with('status', 'Operator added.');
    }

    public function toggleOperator(Request $request, ControlRoom $controlRoom, Operator $operator): RedirectResponse
    {
        $this->authorize('manage', $controlRoom);
        abort_unless($operator->control_room_id === $controlRoom->id, 404);
        $operator->update(['is_active' => ! $operator->is_active]);

        return redirect(route('control-rooms.admin', $controlRoom).'#operators')->with('status', $operator->name().($operator->is_active ? ' activated.' : ' deactivated.'));
    }

    /** The role class is room configuration over a mirrored row, so it is audited here rather than through Auditable. */
    public function updateEquipmentRole(Request $request, ControlRoom $controlRoom, Equipment $equipment): RedirectResponse
    {
        $this->authorize('manage', $controlRoom);
        abort_unless($equipment->control_room_id === $controlRoom->id, 404);
        $validated = $request->validate(['role_class' => ['required', Rule::in(RoleClass::ALL)], 'reason' => ['required', 'string', 'max:500']]);

        $old = $equipment->role_class;
        $equipment->forceFill(['role_class' => $validated['role_class'], 'role_class_source' => 'manual'])->save();
        $this->audits->record('updated', $equipment, ['role_class' => ['old' => $old, 'new' => $validated['role_class']]], $validated['reason'], $controlRoom, $request->user());

        return redirect(route('control-rooms.admin', $controlRoom).'#roles')->with('status', $equipment->name.' is now a '.strtolower(RoleClass::label($validated['role_class'])).'.');
    }

    public function updateSettings(Request $request, ControlRoom $controlRoom): RedirectResponse
    {
        $this->authorize('manage', $controlRoom);
        $rules = ['reason' => ['required', 'string', 'max:500']];
        foreach (self::SETTING_KEYS as $key) {
            $rules[$key] = ['required', 'numeric', 'min:0', 'max:100000'];
        }
        $validated = $request->validate($rules);

        $changes = [];
        $settings = $controlRoom->settings();
        foreach (self::SETTING_KEYS as $key) {
            $old = $settings->get($key);
            $new = $validated[$key] + 0;
            if ($old != $new) {
                $settings->set($key, $new);
                $changes[$key] = ['old' => $old, 'new' => $new];
            }
        }

        if ($changes !== []) {
            $this->audits->record('updated', $controlRoom, $changes, $validated['reason'], $controlRoom, $request->user());
        }

        return redirect(route('control-rooms.admin', $controlRoom).'#thresholds')->with('status', $changes === [] ? 'No threshold changed.' : count($changes).' threshold(s) updated.');
    }
}
