<?php

namespace App\Actions\Documents;

use App\Models\Document;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class CancelDocument
{
    public function handle(Document $document): Document
    {
        Gate::authorize('cancel', $document);

        return DB::transaction(function () use ($document): Document {
            $lockedDocument = Document::query()->lockForUpdate()->findOrFail($document->id);
            Gate::authorize('cancel', $lockedDocument);
            $lockedDocument->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'current_handler_id' => null,
            ]);
            $lockedDocument->actions()->create([
                'performed_by' => auth()->id(),
                'action' => 'cancelled',
            ]);

            return $lockedDocument;
        });
    }
}
