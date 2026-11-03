<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['document_id', 'document_route_id', 'performed_by', 'action', 'remarks', 'metadata'])]
class DocumentAction extends Model
{
    public const UPDATED_AT = null;

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function documentRoute(): BelongsTo
    {
        return $this->belongsTo(DocumentRoute::class);
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }
}
