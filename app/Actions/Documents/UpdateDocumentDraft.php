<?php

namespace App\Actions\Documents;

use App\Models\Document;
use App\Models\DocumentType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

class UpdateDocumentDraft
{
    /**
     * @param  array{title?: mixed, description?: mixed, document_type_id?: mixed, custom_document_type?: mixed}  $attributes
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
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value !== 'other' && (! is_numeric($value) || ! DocumentType::query()->whereKey($value)->where('is_active', true)->exists())) {
                        $fail('Select an active document type or choose Other / Specify.');
                    }
                },
            ],
            'custom_document_type' => ['required_if:document_type_id,other', 'nullable', 'string', 'max:120'],
        ])->validate();

        return DB::transaction(function () use ($document, $validated): Document {
            $lockedDocument = Document::query()->lockForUpdate()->findOrFail($document->id);
            Gate::authorize('updateDraft', $lockedDocument);
            if (($validated['document_type_id'] ?? null) === 'other') {
                $validated['document_type_id'] = null;
                $validated['custom_document_type'] = trim($validated['custom_document_type']);
            } elseif (array_key_exists('document_type_id', $validated)) {
                $validated['custom_document_type'] = null;
            }
            $lockedDocument->update($validated);
            $lockedDocument->actions()->create([
                'performed_by' => auth()->id(),
                'action' => 'draft_updated',
            ]);

            return $lockedDocument;
        });
    }
}
