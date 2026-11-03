<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'document_id',
    'document_version_id',
    'from_user_id',
    'to_user_id',
    'from_division_id',
    'to_division_id',
    'route_type',
    'status',
    'remarks',
    'forwarded_at',
    'acknowledged_at',
    'declined_at',
    'decline_reason',
])]
class DocumentRoute extends Model
{
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function documentVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }

    public function fromDivision(): BelongsTo
    {
        return $this->belongsTo(Division::class, 'from_division_id');
    }

    public function toDivision(): BelongsTo
    {
        return $this->belongsTo(Division::class, 'to_division_id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(DocumentAction::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'forwarded_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'declined_at' => 'datetime',
        ];
    }
}
