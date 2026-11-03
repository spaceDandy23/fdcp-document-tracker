<?php

namespace App\Actions\Documents;

use App\Models\Document;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CreateDocument
{
    /**
     * @param  array{title?: mixed, document_type_id?: mixed, description?: mixed}  $attributes
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
            'document_type_id' => [
                'required',
                'integer',
                Rule::exists('document_types', 'id')->where('is_active', true),
            ],
            'description' => ['nullable', 'string'],
        ])->validate();

        return DB::transaction(function () use ($creator, $validated): Document {
            $document = Document::create([
                'tracking_number' => null,
                'title' => $validated['title'],
                'description' => $validated['description'] ?? null,
                'document_type_id' => $validated['document_type_id'],
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
