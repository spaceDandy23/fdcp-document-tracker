<?php

namespace App\Actions\Documents;

use App\Models\DocumentType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ToggleDocumentType
{
    public function handle(DocumentType $documentType): DocumentType
    {
        Gate::authorize('update', $documentType);

        return DB::transaction(function () use ($documentType): DocumentType {
            $locked = DocumentType::query()->lockForUpdate()->findOrFail($documentType->id);
            Gate::authorize('update', $locked);
            $locked->update(['is_active' => ! $locked->is_active]);

            return $locked;
        });
    }
}
