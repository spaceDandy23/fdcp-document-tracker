<?php

namespace App\Actions\Documents;

use App\Models\Document;
use App\Models\DocumentRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class AcknowledgeDocumentRoute
{
    public function handle(DocumentRoute $route): DocumentRoute
    {
        Gate::authorize('acknowledge', [$route->document, $route]);

        return DB::transaction(function () use ($route): DocumentRoute {
            $lockedRoute = DocumentRoute::query()->lockForUpdate()->findOrFail($route->id);
            $lockedDocument = Document::query()->lockForUpdate()->findOrFail($lockedRoute->document_id);
            Gate::authorize('acknowledge', [$lockedDocument, $lockedRoute]);
            $lockedRoute->update([
                'status' => 'acknowledged',
                'acknowledged_at' => now(),
            ]);
            $lockedDocument->update([
                'current_handler_id' => $lockedRoute->to_user_id,
                'status' => 'in_progress',
            ]);
            $lockedDocument->actions()->create([
                'document_route_id' => $lockedRoute->id,
                'performed_by' => auth()->id(),
                'action' => 'acknowledged',
            ]);

            return $lockedRoute;
        });
    }
}
