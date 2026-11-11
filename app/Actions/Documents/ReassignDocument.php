<?php

namespace App\Actions\Documents;

use App\Models\Document;
use App\Models\DocumentRoute;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ReassignDocument
{
    /**
     * @param  array{recipient_id?: mixed, reason?: mixed}  $attributes
     */
    public function handle(Document $document, array $attributes): DocumentRoute
    {
        Gate::authorize('reassign', $document);

        if (is_string($attributes['reason'] ?? null)) {
            $attributes['reason'] = trim($attributes['reason']);
        }

        $validated = Validator::make($attributes, [
            'recipient_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where(fn (Builder $query) => $query
                    ->where('is_active', true)
                    ->whereNotNull('division_id')),
            ],
            'reason' => ['required', 'string', 'max:1000'],
        ])->validate();

        return DB::transaction(function () use ($document, $validated): DocumentRoute {
            $lockedDocument = Document::query()->lockForUpdate()->findOrFail($document->id);
            Gate::authorize('reassign', $lockedDocument);

            if ($lockedDocument->current_handler_id === null || ! in_array($lockedDocument->status, ['pending_acknowledgment', 'in_progress', 'revision_requested'], true)) {
                throw ValidationException::withMessages([
                    'document' => 'Only nonterminal documents in active processing may be reassigned.',
                ]);
            }

            $previousHandler = User::query()->lockForUpdate()->find($lockedDocument->current_handler_id);
            if ($previousHandler === null || $previousHandler->is_active || $previousHandler->division_id === null) {
                throw ValidationException::withMessages([
                    'document' => 'The document is no longer assigned to an inactive employee.',
                ]);
            }

            $recipient = User::query()
                ->where('is_active', true)
                ->whereNotNull('division_id')
                ->lockForUpdate()
                ->find($validated['recipient_id']);

            if ($recipient === null || $recipient->id === $previousHandler->id) {
                throw ValidationException::withMessages([
                    'recipient_id' => 'Select another active employee with an assigned division.',
                ]);
            }

            if ($lockedDocument->current_version_id === null
                || ! $lockedDocument->versions()->whereKey($lockedDocument->current_version_id)->exists()) {
                throw ValidationException::withMessages([
                    'document' => 'A current document version is required for reassignment.',
                ]);
            }

            $now = now();
            $previousStatus = $lockedDocument->status;
            $isRevisionAssignment = $previousStatus === 'revision_requested';
            $lockedDocument->routes()
                ->where('status', 'pending')
                ->update([
                    'status' => 'declined',
                    'declined_at' => $now,
                    'decline_reason' => 'Superseded by administrator reassignment.',
                    'updated_at' => $now,
                ]);

            $route = $lockedDocument->routes()->create([
                'document_version_id' => $lockedDocument->current_version_id,
                'from_user_id' => $previousHandler->id,
                'to_user_id' => $recipient->id,
                'from_division_id' => $previousHandler->division_id,
                'to_division_id' => $recipient->division_id,
                'route_type' => 'reassignment',
                'status' => $isRevisionAssignment ? 'acknowledged' : 'pending',
                'remarks' => $validated['reason'],
                'forwarded_at' => $now,
                'acknowledged_at' => $isRevisionAssignment ? $now : null,
            ]);

            $lockedDocument->update([
                'current_handler_id' => $recipient->id,
                'status' => $isRevisionAssignment ? 'revision_requested' : 'pending_acknowledgment',
            ]);

            $lockedDocument->actions()->create([
                'document_route_id' => $route->id,
                'performed_by' => auth()->id(),
                'action' => 'reassigned',
                'remarks' => $validated['reason'],
                'metadata' => [
                    'previous_handler_id' => $previousHandler->id,
                    'new_handler_id' => $recipient->id,
                    'administrator_id' => auth()->id(),
                    'previous_status' => $previousStatus,
                    'reassigned_at' => $now->toIso8601String(),
                ],
            ]);

            app(NotifyDocumentWorkflow::class)->handle(
                $recipient,
                $lockedDocument,
                'document_reassigned',
                "Document {$lockedDocument->tracking_number} was reassigned to you by an administrator.",
            );

            return $route;
        });
    }
}
