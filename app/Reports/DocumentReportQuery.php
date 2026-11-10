<?php

namespace App\Reports;

use App\Actions\Documents\ListDocuments;
use App\Models\Division;
use App\Models\Document;
use App\Models\DocumentAction;
use App\Models\DocumentRoute;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class DocumentReportQuery
{
    /** @var array<int, string> */
    public const STATUSES = ['pending_acknowledgment', 'in_progress', 'completed', 'cancelled', 'revision_requested'];

    /** @param array<string, mixed> $filters */
    public function documents(array $filters = []): Builder
    {
        $this->authorize();

        return app(ListDocuments::class)->query()
            ->whereNotNull('submitted_at')
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('submitted_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $date) => $query->whereDate('submitted_at', '<=', $date))
            ->when($filters['division_id'] ?? null, function (Builder $query, int $divisionId): void {
                $query->where(function (Builder $divisionQuery) use ($divisionId): void {
                    $divisionQuery->where('origin_division_id', $divisionId)
                        ->orWhereHas('routes', fn (Builder $routeQuery) => $routeQuery
                            ->where('from_division_id', $divisionId)
                            ->orWhere('to_division_id', $divisionId));
                });
            })
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['document_type'] ?? null, function (Builder $query, string $type): void {
                if ($type === 'custom') {
                    $query->whereNotNull('custom_document_type');
                } else {
                    $query->where('document_type_id', $type);
                }
            });
    }

    /** @param array<string, mixed> $filters
     * @return array<string, int|float|null>
     */
    public function summary(array $filters = []): array
    {
        $documents = $this->documents($filters);
        $counts = (clone $documents)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $durationExpression = config('database.default') === 'sqlite'
            ? 'avg((julianday(completed_at) - julianday(submitted_at)) * 86400)'
            : 'avg(timestampdiff(second, submitted_at, completed_at))';
        $average = (clone $documents)->where('status', 'completed')->whereNotNull('completed_at')
            ->selectRaw($durationExpression.' as seconds')->value('seconds');

        return [
            'total_submitted' => (clone $documents)->count(),
            'pending_acknowledgment' => (int) ($counts['pending_acknowledgment'] ?? 0),
            'in_progress' => (int) ($counts['in_progress'] ?? 0),
            'completed' => (int) ($counts['completed'] ?? 0),
            'cancelled' => (int) ($counts['cancelled'] ?? 0),
            'revision_requested' => (int) ($counts['revision_requested'] ?? 0),
            'average_processing_seconds' => $average === null ? null : (float) $average,
        ];
    }

    /** @param array<string, mixed> $filters
     * @return Collection<int, object>
     */
    public function volume(array $filters = []): Collection
    {
        return $this->documents($filters)->selectRaw('date(submitted_at) as day, count(*) as total')
            ->groupByRaw('date(submitted_at)')->orderBy('day')->get();
    }

    /** @param array<string, mixed> $filters
     * @return Collection<int, object>
     */
    public function divisionMetrics(array $filters = []): Collection
    {
        $documentIds = $this->documents($filters)->select('documents.id');

        return Division::query()
            ->select(['divisions.id', 'divisions.name', 'divisions.code'])
            ->selectSub(Document::query()->whereIn('id', $documentIds)->whereColumn('origin_division_id', 'divisions.id')->selectRaw('count(distinct id)'), 'originated')
            ->selectSub(DocumentRoute::query()->whereIn('document_id', $documentIds)->whereColumn('to_division_id', 'divisions.id')->selectRaw('count(distinct document_id)'), 'received')
            ->selectSub(DocumentRoute::query()->whereIn('document_id', $documentIds)->where('route_type', 'forward')->whereColumn('from_division_id', 'divisions.id')->selectRaw('count(distinct document_id)'), 'forwarded')
            ->selectSub(DocumentAction::query()->join('users', 'users.id', '=', 'document_actions.performed_by')->whereIn('document_actions.document_id', $documentIds)->where('document_actions.action', 'completed')->whereColumn('users.division_id', 'divisions.id')->selectRaw('count(distinct document_actions.document_id)'), 'completed')
            ->selectSub(Document::query()->join('users as handlers', 'handlers.id', '=', 'documents.current_handler_id')->whereIn('documents.id', $documentIds)->whereIn('documents.status', ['pending_acknowledgment', 'in_progress', 'revision_requested'])->whereColumn('handlers.division_id', 'divisions.id')->selectRaw('count(distinct documents.id)'), 'assigned')
            ->orderBy('name')->get()
            ->filter(fn (object $metric) => $metric->originated > 0 || $metric->received > 0 || $metric->forwarded > 0 || $metric->completed > 0 || $metric->assigned > 0)
            ->values();
    }

    /** @param array<string, mixed> $filters
     * @return Collection<int, Document>
     */
    public function agingDocuments(array $filters = []): Collection
    {
        return $this->documents($filters)->whereIn('status', ['pending_acknowledgment', 'in_progress', 'revision_requested'])
            ->with(['creator:id,name', 'currentHandler:id,name', 'documentType:id,name'])
            ->withMax('actions as last_workflow_at', 'created_at')
            ->orderByRaw('coalesce(last_workflow_at, submitted_at)')->get();
    }

    public function agingBucket(Document $document): string
    {
        $startedAt = $document->last_workflow_at ?? $document->submitted_at;
        if ($startedAt === null) {
            return 'Unknown';
        }

        $days = Carbon::parse($startedAt)->diffInDays(now());

        return match (true) {
            $days < 3 => 'Under 3 days',
            $days < 7 => '3–6 days',
            $days < 14 => '7–13 days',
            default => '14+ days',
        };
    }

    private function authorize(): void
    {
        /** @var User $user */
        $user = auth()->user();
        abort_unless($user->is_admin || $user->is_division_head, 403);
    }
}
