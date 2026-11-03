<?php

namespace App\Actions\Documents;

use App\Models\Document;
use App\Models\DocumentRoute;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SubmitDocument
{
    /**
     * @param  array{recipient_id?: mixed}  $attributes
     */
    public function handle(Document $document, array $attributes): DocumentRoute
    {
        Gate::authorize('submit', $document);

        $validated = Validator::make($attributes, [
            'recipient_id' => [
                'required',
                'integer',
                Rule::notIn([auth()->id()]),
                Rule::exists('users', 'id')->where(fn (Builder $query) => $query
                    ->where('is_active', true)
                    ->whereNotNull('division_id')),
            ],
        ])->validate();

        return DB::transaction(function () use ($document, $validated): DocumentRoute {
            $lockedDocument = Document::query()->lockForUpdate()->findOrFail($document->id);
            Gate::authorize('submit', $lockedDocument);

            if (! $lockedDocument->documentType()->where('is_active', true)->exists()) {
                throw ValidationException::withMessages([
                    'document_type_id' => 'The selected document type is inactive.',
                ]);
            }

            if ($lockedDocument->current_version_id === null
                || ! $lockedDocument->versions()->whereKey($lockedDocument->current_version_id)->exists()) {
                throw ValidationException::withMessages([
                    'document' => 'A main document version is required before submission.',
                ]);
            }

            $sender = User::query()
                ->whereNotNull('division_id')
                ->lockForUpdate()
                ->find($lockedDocument->current_handler_id);
            $recipient = User::query()
                ->where('is_active', true)
                ->whereNotNull('division_id')
                ->lockForUpdate()
                ->find($validated['recipient_id']);

            if ($sender === null || $recipient === null || $recipient->id === $sender->id) {
                throw ValidationException::withMessages([
                    'recipient_id' => 'Select another active employee with an assigned division.',
                ]);
            }

            $now = now();
            $trackingNumber = $lockedDocument->tracking_number
                ?? 'FDCP-'.$now->format('Y').'-'.Str::upper((string) Str::ulid());
            $route = $lockedDocument->routes()->create([
                'document_version_id' => $lockedDocument->current_version_id,
                'from_user_id' => $sender->id,
                'to_user_id' => $recipient->id,
                'from_division_id' => $sender->division_id,
                'to_division_id' => $recipient->division_id,
                'route_type' => 'initial',
                'status' => 'pending',
                'forwarded_at' => $now,
            ]);

            $lockedDocument->update([
                'tracking_number' => $trackingNumber,
                'current_handler_id' => $recipient->id,
                'status' => 'pending_acknowledgment',
                'submitted_at' => $now,
            ]);
            $lockedDocument->actions()->create([
                'performed_by' => auth()->id(),
                'action' => 'submitted',
                'metadata' => ['tracking_number' => $trackingNumber],
            ]);
            $lockedDocument->actions()->create([
                'document_route_id' => $route->id,
                'performed_by' => auth()->id(),
                'action' => 'forwarded',
            ]);

            return $route;
        });
    }
}
