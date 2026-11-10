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

class ReturnDocumentForRevision
{
    /**
     * @param  array{reason?: mixed, recipient_id?: mixed}  $attributes
     */
    public function handle(Document $document, array $attributes): DocumentRoute
    {
        Gate::authorize('returnForRevision', $document);

        $validated = Validator::make($attributes, [
            'reason' => ['required', 'string'],
            'recipient_id' => [
                'nullable',
                'integer',
                Rule::notIn([auth()->id()]),
                Rule::exists('users', 'id')->where(fn (Builder $query) => $query
                    ->where('is_active', true)
                    ->whereNotNull('division_id')),
            ],
        ])->validate();

        return DB::transaction(function () use ($document, $validated): DocumentRoute {
            $lockedDocument = Document::query()->lockForUpdate()->findOrFail($document->id);
            Gate::authorize('returnForRevision', $lockedDocument);

            if ($lockedDocument->current_version_id === null
                || ! $lockedDocument->versions()->whereKey($lockedDocument->current_version_id)->exists()) {
                throw ValidationException::withMessages([
                    'document' => 'A current document version is required for revision.',
                ]);
            }

            if ($lockedDocument->routes()->where('status', 'pending')->exists()) {
                throw ValidationException::withMessages([
                    'document' => 'The pending transfer must be resolved before requesting revision.',
                ]);
            }

            $immediateSender = $lockedDocument->routes()
                ->where('to_user_id', $lockedDocument->current_handler_id)
                ->where('status', 'acknowledged')
                ->latest('id')
                ->first();

            if ($immediateSender === null) {
                throw ValidationException::withMessages([
                    'recipient_id' => 'No eligible previous handler is available.',
                ]);
            }

            $eligibleHandlerIds = $lockedDocument->routes()
                ->pluck('from_user_id')
                ->merge($lockedDocument->routes()->where('status', 'acknowledged')->pluck('to_user_id'))
                ->push($lockedDocument->created_by)
                ->unique();
            $recipientId = $validated['recipient_id'] ?? $immediateSender->from_user_id;

            if (! $eligibleHandlerIds->contains($recipientId)) {
                throw ValidationException::withMessages([
                    'recipient_id' => 'The revision recipient must be a previous handler.',
                ]);
            }

            $sender = User::query()->lockForUpdate()->findOrFail($lockedDocument->current_handler_id);
            $recipient = User::query()
                ->where('is_active', true)
                ->whereNotNull('division_id')
                ->lockForUpdate()
                ->find($recipientId);

            if ($recipient === null || $recipient->id === $sender->id) {
                throw ValidationException::withMessages([
                    'recipient_id' => 'Select an active previous handler with an assigned division.',
                ]);
            }

            $now = now();
            $route = $lockedDocument->routes()->create([
                'document_version_id' => $lockedDocument->current_version_id,
                'from_user_id' => $sender->id,
                'to_user_id' => $recipient->id,
                'from_division_id' => $sender->division_id,
                'to_division_id' => $recipient->division_id,
                'route_type' => 'revision_return',
                'status' => 'acknowledged',
                'remarks' => $validated['reason'],
                'forwarded_at' => $now,
                'acknowledged_at' => $now,
            ]);

            $lockedDocument->update([
                'current_handler_id' => $recipient->id,
                'status' => 'revision_requested',
            ]);
            $lockedDocument->actions()->create([
                'document_route_id' => $route->id,
                'performed_by' => $sender->id,
                'action' => 'revision_returned',
                'remarks' => $validated['reason'],
            ]);

            app(NotifyDocumentWorkflow::class)->handle(
                $recipient,
                $lockedDocument,
                'document_returned_for_revision',
                "Document {$lockedDocument->tracking_number} was returned to you for revision.",
            );

            return $route;
        });
    }
}
