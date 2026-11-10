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

class ForwardDocument
{
    /**
     * @param  array{recipient_id?: mixed, remarks?: mixed}  $attributes
     */
    public function handle(Document $document, array $attributes): DocumentRoute
    {
        Gate::authorize('forward', $document);

        $validated = Validator::make($attributes, [
            'recipient_id' => [
                'required',
                'integer',
                Rule::notIn([auth()->id()]),
                Rule::exists('users', 'id')->where(fn (Builder $query) => $query
                    ->where('is_active', true)
                    ->whereNotNull('division_id')),
            ],
            'remarks' => ['nullable', 'string'],
        ])->validate();

        return DB::transaction(function () use ($document, $validated): DocumentRoute {
            $lockedDocument = Document::query()->lockForUpdate()->findOrFail($document->id);
            Gate::authorize('forward', $lockedDocument);

            if ($lockedDocument->current_version_id === null
                || ! $lockedDocument->versions()->whereKey($lockedDocument->current_version_id)->exists()) {
                throw ValidationException::withMessages([
                    'document' => 'A current document version is required for forwarding.',
                ]);
            }

            if ($lockedDocument->routes()->where('status', 'pending')->exists()) {
                throw ValidationException::withMessages([
                    'document' => 'The pending transfer must be resolved before forwarding again.',
                ]);
            }

            $sender = User::query()->lockForUpdate()->findOrFail($lockedDocument->current_handler_id);
            $recipient = User::query()
                ->where('is_active', true)
                ->whereNotNull('division_id')
                ->lockForUpdate()
                ->find($validated['recipient_id']);

            if ($recipient === null || $recipient->id === $sender->id) {
                throw ValidationException::withMessages([
                    'recipient_id' => 'Select another active employee with an assigned division.',
                ]);
            }

            $immediateSenderId = $lockedDocument->routes()
                ->where('to_user_id', $sender->id)
                ->where('status', 'acknowledged')
                ->latest('id')
                ->value('from_user_id');

            if ($immediateSenderId !== null && $recipient->id === $immediateSenderId) {
                throw ValidationException::withMessages([
                    'recipient_id' => 'Use Return for revision to send the document back to its immediate sender.',
                ]);
            }

            $route = $lockedDocument->routes()->create([
                'document_version_id' => $lockedDocument->current_version_id,
                'from_user_id' => $sender->id,
                'to_user_id' => $recipient->id,
                'from_division_id' => $sender->division_id,
                'to_division_id' => $recipient->division_id,
                'route_type' => 'forward',
                'status' => 'pending',
                'remarks' => $validated['remarks'] ?? null,
                'forwarded_at' => now(),
            ]);

            $lockedDocument->update([
                'current_handler_id' => $recipient->id,
                'status' => 'pending_acknowledgment',
            ]);
            $lockedDocument->actions()->create([
                'document_route_id' => $route->id,
                'performed_by' => $sender->id,
                'action' => 'forwarded',
                'remarks' => $validated['remarks'] ?? null,
            ]);

            app(NotifyDocumentWorkflow::class)->handle(
                $recipient,
                $lockedDocument,
                'document_forwarded',
                "Document {$lockedDocument->tracking_number} was sent to you.",
            );

            return $route;
        });
    }
}
