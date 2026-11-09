<?php

namespace App\Actions\Documents;

use App\Models\Document;
use App\Models\DocumentType;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

class CreateDocument
{
    /**
     * @param  array{title?: mixed, document_type_id?: mixed, custom_document_type?: mixed, description?: mixed}  $attributes
     */
    public function handle(array $attributes): Document
    {
        $creator = Auth::user();

        if (! $creator instanceof User) {
            throw new AuthenticationException;
        }

        Gate::authorize('create', Document::class);

        $validated = Validator::make($attributes, [
            'title' => ['required', 'string', 'max:255'],
            'document_type_id' => ['required', function (string $attribute, mixed $value, \Closure $fail): void {
                if ($value === 'other') {
                    return;
                }
                if (! is_numeric($value) || ! DocumentType::query()->whereKey($value)->where('is_active', true)->exists()) {
                    $fail('Select an active document type or choose Other / Specify.');
                }
            }],
            'custom_document_type' => ['required_if:document_type_id,other', 'nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string'],
        ])->validate();

        return DB::transaction(function () use ($creator, $validated): Document {
            $document = Document::create([
                'tracking_number' => null,
                'title' => $validated['title'],
                'description' => $validated['description'] ?? null,
                'document_type_id' => $validated['document_type_id'] === 'other' ? null : $validated['document_type_id'],
                'custom_document_type' => $validated['document_type_id'] === 'other' ? trim($validated['custom_document_type']) : null,
                'origin_division_id' => $creator->division_id,
                'created_by' => $creator->id,
                'current_handler_id' => $creator->id,
                'current_version_id' => null,
                'status' => 'draft',
            ]);

            $document->actions()->create([
                'performed_by' => $creator->id,
                'action' => 'created',
            ]);

            return $document;
        });
    }
}
