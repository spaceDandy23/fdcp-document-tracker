<?php

namespace App\Actions\Documents;

use App\Models\DocumentType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class SaveDocumentType
{
    /** @param array{name?: mixed, code?: mixed} $attributes */
    public function handle(array $attributes, ?DocumentType $documentType = null): DocumentType
    {
        Gate::authorize($documentType ? 'update' : 'create', $documentType ?? DocumentType::class);
        $validated = Validator::make($attributes, [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', Rule::unique('document_types', 'code')->ignore($documentType)],
        ])->validate();

        return DB::transaction(function () use ($documentType, $validated): DocumentType {
            $documentType ??= new DocumentType(['is_active' => true]);
            $documentType->fill($validated)->save();

            return $documentType;
        });
    }
}
