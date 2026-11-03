<?php

namespace App\Actions\Documents;

use App\Models\Document;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class UpdateDocumentDraft
{
    /**
     * @param  array{title?: mixed, description?: mixed, document_type_id?: mixed}  $attributes
     */
    public function handle(Document $document, array $attributes): Document
    {
        Gate::authorize('updateDraft', $document);

        $validated = Validator::make($attributes, [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'document_type_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('document_types', 'id')->where('is_active', true),
            ],
        ])->validate();

        return DB::transaction(function () use ($document, $validated): Document {
            $lockedDocument = Document::query()->lockForUpdate()->findOrFail($document->id);
            Gate::authorize('updateDraft', $lockedDocument);
            $lockedDocument->update($validated);
            $lockedDocument->actions()->create([
                'performed_by' => auth()->id(),
                'action' => 'draft_updated',
            ]);

            return $lockedDocument;
        });
    }
}
