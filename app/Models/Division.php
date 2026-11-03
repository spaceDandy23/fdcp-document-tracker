<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['sso_division_id', 'name', 'code', 'is_active', 'last_synced_at'])]
class Division extends Model
{
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function originatingDocuments(): HasMany
    {
        return $this->hasMany(Document::class, 'origin_division_id');
    }

    public function sentRoutes(): HasMany
    {
        return $this->hasMany(DocumentRoute::class, 'from_division_id');
    }

    public function receivedRoutes(): HasMany
    {
        return $this->hasMany(DocumentRoute::class, 'to_division_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }
}
