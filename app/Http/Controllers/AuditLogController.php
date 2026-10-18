<?php

namespace App\Http\Controllers;

use App\Models\AuditEntry;
use App\Models\ControlRoom;
use App\Services\Audits;
use App\Support\Csv;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Read-only audit explorer for supervisors, managers and administrators.
 */
class AuditLogController extends Controller
{
    public function index(Request $request, Audits $audits): View|StreamedResponse
    {
        $this->authorize('viewAny', AuditEntry::class);

        $filters = $request->validate([
            'control_room_id' => ['nullable', 'integer'],
            'action' => ['nullable', 'string', 'max:32'],
            'entity_type' => ['nullable', 'string', 'max:255'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'export' => ['nullable', 'in:csv'],
        ]);

        $query = AuditEntry::query()
            ->visibleTo($request->user())
            ->with(['user', 'controlRoom'])
            ->when($filters['control_room_id'] ?? null, fn ($q, $id) => $q->where('control_room_id', $id))
            ->when($filters['action'] ?? null, fn ($q, $action) => $q->where('action', $action))
            ->when($filters['entity_type'] ?? null, fn ($q, $type) => $q->where('entity_type', $type))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where('occurred_at', '>=', Carbon::parse($from)->startOfDay()))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where('occurred_at', '<=', Carbon::parse($to)->endOfDay()))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');

        if (($filters['export'] ?? null) === 'csv') {
            $this->authorize('export', AuditEntry::class);

            $audits->record('generate', null, ['filters' => ['old' => null, 'new' => $filters]], entityType: 'audit_log');

            return $this->streamCsv($query);
        }

        return view('audit.index', [
            'entries' => $query->paginate(50)->withQueryString(),
            'controlRooms' => ControlRoom::accessibleBy($request->user())->orderBy('name')->get(),
            'actions' => AuditEntry::ACTIONS,
            'filters' => $filters,
        ]);
    }

    /**
     * @param  Builder<AuditEntry>  $query
     */
    private function streamCsv($query): StreamedResponse
    {
        return response()->streamDownload(function () use ($query): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['occurred_at', 'user', 'role', 'control_room', 'entity_type', 'entity_id', 'action', 'changes', 'reason', 'automatic']);

            $query->chunk(500, function ($rows) use ($out): void {
                foreach ($rows as $row) {
                    fputcsv($out, Csv::row([
                        $row->occurred_at->toIso8601String(),
                        $row->user?->name,
                        $row->user_role,
                        $row->controlRoom?->name,
                        class_basename($row->entity_type),
                        $row->entity_id,
                        $row->action,
                        $row->changes === null ? '' : json_encode($row->changes),
                        $row->reason,
                        $row->automatic ? 'yes' : 'no',
                    ]));
                }
            });

            fclose($out);
        }, 'audit-log-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv']);
    }
}
