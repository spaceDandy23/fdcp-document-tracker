<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\DocumentRoute;
use App\Models\User;

class DocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }

    public function view(User $user, Document $document): bool
    {
        if ($user->is_admin || $user->id === $document->created_by || $user->id === $document->current_handler_id) {
            return true;
        }

        if ($document->routes()->where(fn ($query) => $query
            ->where('from_user_id', $user->id)
            ->orWhere('to_user_id', $user->id))->exists()) {
            return true;
        }

        return $user->is_division_head && $user->division_id !== null && (
            $document->origin_division_id === $user->division_id
            || $document->routes()->where(fn ($query) => $query
                ->where('from_division_id', $user->division_id)
                ->orWhere('to_division_id', $user->division_id))->exists()
        );
    }

    public function create(User $user): bool
    {
        return $user->is_active && $user->division_id !== null;
    }

    public function updateDraft(User $user, Document $document): bool
    {
        return $user->id === $document->created_by && $document->status === 'draft';
    }

    public function uploadVersion(User $user, Document $document): bool
    {
        return $this->updateDraft($user, $document)
            || ($user->id === $document->current_handler_id && $document->status === 'revision_requested');
    }

    public function uploadAttachment(User $user, Document $document): bool
    {
        return $user->is_active && (
            $this->updateDraft($user, $document)
            || ($user->id === $document->current_handler_id
                && in_array($document->status, ['in_progress', 'revision_requested'], true))
        );
    }

    public function removeAttachment(User $user, Document $document): bool
    {
        return $this->uploadAttachment($user, $document);
    }

    public function submit(User $user, Document $document): bool
    {
        return $this->updateDraft($user, $document)
            || ($user->id === $document->current_handler_id && $document->status === 'revision_requested');
    }

    public function forward(User $user, Document $document): bool
    {
        return $user->id === $document->current_handler_id && $document->status === 'in_progress';
    }

    public function download(User $user, Document $document): bool
    {
        return $this->view($user, $document);
    }

    public function acknowledge(User $user, Document $document, DocumentRoute $route): bool
    {
        return $route->document_id === $document->id
            && $route->to_user_id === $user->id
            && $route->status === 'pending'
            && $document->current_handler_id === $user->id
            && $document->status === 'pending_acknowledgment';
    }

    public function decline(User $user, Document $document, DocumentRoute $route): bool
    {
        return $this->acknowledge($user, $document, $route);
    }

    public function returnForRevision(User $user, Document $document): bool
    {
        return $user->id === $document->current_handler_id && $document->status === 'in_progress';
    }

    public function complete(User $user, Document $document): bool
    {
        return $user->id === $document->current_handler_id && $document->status === 'in_progress';
    }

    public function cancel(User $user, Document $document): bool
    {
        return $user->id === $document->created_by && $document->status === 'draft';
    }
}
