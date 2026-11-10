<?php

namespace App\Actions\Documents;

use App\Models\Document;
use App\Models\DocumentRoute;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

class DeclineDocumentRoute
{
    /**
     * @param  array{reason?: mixed}  $attributes
     */
    public function handle(DocumentRoute $route, array $attributes): DocumentRoute
    {
        Gate::authorize('decline', [$route->document, $route]);

        $validated = Validator::make($attributes, [
            'reason' => ['required', 'string'],
        ])->validate();

        return DB::transaction(function () use ($route, $validated): DocumentRoute {
            $lockedRoute = DocumentRoute::query()->lockForUpdate()->findOrFail($route->id);
            $lockedDocument = Document::query()->lockForUpdate()->findOrFail($lockedRoute->document_id);
            Gate::authorize('decline', [$lockedDocument, $lockedRoute]);
            $lockedRoute->update([
                'status' => 'declined',
                'declined_at' => now(),
                'decline_reason' => $validated['reason'],
            ]);
            $lockedDocument->update([
                'current_handler_id' => $lockedRoute->from_user_id,
                'status' => $lockedRoute->route_type === 'revision_resubmission'
                    ? 'revision_requested'
                    : 'in_progress',
            ]);
            $lockedDocument->actions()->create([
                'document_route_id' => $lockedRoute->id,
                'performed_by' => auth()->id(),
                'action' => 'declined',
                'remarks' => $validated['reason'],
            ]);

            $sender = User::query()->find($lockedRoute->from_user_id);
            if ($sender !== null) {
                app(NotifyDocumentWorkflow::class)->handle(
                    $sender,
                    $lockedDocument,
                    'document_declined',
                    "Your transfer of document {$lockedDocument->tracking_number} was declined.",
                );
            }

            return $lockedRoute;
        });
    }
}
