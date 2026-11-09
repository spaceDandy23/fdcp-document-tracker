<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'tracking_number',
    'title',
    'description',
    'document_type_id',
    'custom_document_type',
    'origin_division_id',
    'created_by',
    'current_handler_id',
    'current_version_id',
    'status',
    'submitted_at',
    'completed_at',
    'cancelled_at',
])]
class Document extends Model
{
    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function getDocumentTypeLabelAttribute(): string
    {
        return $this->custom_document_type ?: $this->documentType?->name ?? 'Unknown type';
    }

    public function originDivision(): BelongsTo
    {
        return $this->belongsTo(Division::class, 'origin_division_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function currentHandler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'current_handler_id');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'current_version_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class);
    }

    public function routes(): HasMany
    {
        return $this->hasMany(DocumentRoute::class);
    }

    public function actions(): HasMany
    {
        return $this->hasMany(DocumentAction::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(DocumentAttachment::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }
}
