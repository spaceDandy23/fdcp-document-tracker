<?php

namespace App\Actions\Documents;

use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class CompleteDocument
{
    public function handle(Document $document): Document
    {
        Gate::authorize('complete', $document);

        return DB::transaction(function () use ($document): Document {
            $lockedDocument = Document::query()->lockForUpdate()->findOrFail($document->id);
            Gate::authorize('complete', $lockedDocument);
            $lockedDocument->update([
                'status' => 'completed',
                'completed_at' => now(),
                'current_handler_id' => null,
            ]);
            $lockedDocument->actions()->create([
                'performed_by' => auth()->id(),
                'action' => 'completed',
            ]);

            if ($lockedDocument->created_by !== auth()->id()) {
                $creator = User::query()->find($lockedDocument->created_by);
                if ($creator !== null) {
                    app(NotifyDocumentWorkflow::class)->handle(
                        $creator,
                        $lockedDocument,
                        'document_completed',
                        "Document {$lockedDocument->tracking_number} was completed.",
                    );
                }
            }

            return $lockedDocument;
        });
    }
}
