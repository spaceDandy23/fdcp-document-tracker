<?php

namespace App\Actions\Documents;

use App\Models\Document;
use App\Models\DocumentAttachment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RemoveDocumentAttachment
{
    /**
     * @param  array{reason?: mixed}  $attributes
     */
    public function handle(Document $document, DocumentAttachment $attachment, array $attributes): DocumentAttachment
    {
        Gate::authorize('removeAttachment', $document);

        $validated = Validator::make($attributes, [
            'reason' => ['required', 'string', 'max:1000'],
        ])->validate();

        return DB::transaction(function () use ($document, $attachment, $validated): DocumentAttachment {
            $lockedDocument = Document::query()->lockForUpdate()->findOrFail($document->id);
            Gate::authorize('removeAttachment', $lockedDocument);

            $lockedAttachment = $lockedDocument->attachments()
                ->withTrashed()
                ->lockForUpdate()
                ->findOrFail($attachment->id);

            if ($lockedAttachment->trashed()) {
                throw ValidationException::withMessages([
                    'attachment' => 'This attachment has already been removed.',
                ]);
            }

            $lockedAttachment->delete();
            $lockedDocument->actions()->create([
                'performed_by' => auth()->id(),
                'action' => 'attachment_removed',
                'remarks' => $validated['reason'],
                'metadata' => ['document_attachment_id' => $lockedAttachment->id],
            ]);

            return $lockedAttachment;
        });
    }
}
