<?php

namespace App\Actions\Authentication;

use App\Models\Division;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SyncFdcpUser
{
    public function __construct(private FdcpUserInfoAdapter $userinfo) {}

    /**
     * @param  array<string, mixed>  $claims
     */
    public function handle(array $claims): User
    {
        $profile = $this->userinfo->map($claims);

        return DB::transaction(function () use ($profile): User {
            $syncedAt = now();
            $divisionIdentifier = 'code:'.$profile['division_code'];
            $division = Division::query()
                ->where('sso_division_id', $divisionIdentifier)
                ->orWhere('code', $profile['division_code'])
                ->lockForUpdate()
                ->first();

            if ($division === null) {
                $division = new Division([
                    'name' => $profile['division_code'],
                    'is_active' => true,
                ]);
            }

            $division->forceFill([
                'sso_division_id' => $divisionIdentifier,
                'code' => $profile['division_code'],
                'last_synced_at' => $syncedAt,
            ])->save();

            $user = User::query()
                ->where('sso_user_id', $profile['user_id'])
                ->lockForUpdate()
                ->first();

            $emailOwner = User::query()
                ->where('email', $profile['email'])
                ->lockForUpdate()
                ->first();

            if ($user !== null && $emailOwner !== null && ! $user->is($emailOwner)) {
                throw new DomainException('The FDCP email is already linked to another account.');
            }

            if ($user === null && $emailOwner !== null && $emailOwner->sso_user_id !== null) {
                throw new DomainException('The FDCP email is already linked to another SSO identity.');
            }

            $user ??= $emailOwner ?? new User;

            $attributes = [
                'sso_user_id' => $profile['user_id'],
                'name' => $profile['name'],
                'email' => $profile['email'],
                'email_verified_at' => $syncedAt,
                'division_id' => $division->id,
                'is_active' => $profile['active'],
                'is_division_head' => $profile['is_division_head'],
                'is_admin' => $profile['is_administrator'],
                'last_synced_at' => $syncedAt,
            ];

            if (! $user->exists) {
                $attributes['password'] = Str::random(64);
            }

            $user->forceFill($attributes)->save();

            return $user;
        });
    }
}
