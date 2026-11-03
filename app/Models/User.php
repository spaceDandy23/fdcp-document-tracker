<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'sso_user_id', 'division_id', 'is_active', 'last_synced_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    public function createdDocuments(): HasMany
    {
        return $this->hasMany(Document::class, 'created_by');
    }

    public function assignedDocuments(): HasMany
    {
        return $this->hasMany(Document::class, 'current_handler_id');
    }

    public function uploadedVersions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class, 'uploaded_by');
    }

    public function sentRoutes(): HasMany
    {
        return $this->hasMany(DocumentRoute::class, 'from_user_id');
    }

    public function receivedRoutes(): HasMany
    {
        return $this->hasMany(DocumentRoute::class, 'to_user_id');
    }

    public function performedActions(): HasMany
    {
        return $this->hasMany(DocumentAction::class, 'performed_by');
    }

    public function uploadedAttachments(): HasMany
    {
        return $this->hasMany(DocumentAttachment::class, 'uploaded_by');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }
}
