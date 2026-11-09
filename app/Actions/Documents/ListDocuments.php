<?php

namespace App\Actions\Documents;

use App\Models\Document;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class ListDocuments
{
    /**
     * @return Collection<int, Document>
     */
    public function handle(): Collection
    {
        return $this->query()->latest()->get();
    }

    /**
     * @return Builder<Document>
     */
    public function query(): Builder
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        Gate::authorize('viewAny', Document::class);

        return Document::query()
            ->when(! $user->is_admin, function ($query) use ($user): void {
                $query->where(function ($query) use ($user): void {
                    $query->where('created_by', $user->id)
                        ->orWhere('current_handler_id', $user->id)
                        ->orWhereHas('routes', fn ($query) => $query
                            ->where('from_user_id', $user->id)
                            ->orWhere('to_user_id', $user->id));

                    if ($user->is_division_head && $user->division_id !== null) {
                        $query->orWhere('origin_division_id', $user->division_id)
                            ->orWhereHas('routes', fn ($query) => $query
                                ->where('from_division_id', $user->division_id)
                                ->orWhere('to_division_id', $user->division_id));
                    }
                });
            });
    }
}
