<?php

namespace Database\Seeders;

use App\Models\Division;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DocumentRoutingTestSeeder extends Seeder
{
    private const string SSO_ID_PREFIX = 'dev-test:document-routing:';

    /**
     * Seed synthetic employees for local routing walkthroughs.
     */
    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('The document routing test seeder may only be run in the local environment.');
        }

        DB::transaction(function (): void {
            $itDivision = $this->syncSyntheticDivision('it-section', 'IT Section', 'TEST-IT');
            $hrDivision = $this->syncSyntheticDivision('hr', 'HR', 'TEST-HR');

            $this->syncSyntheticUser('it-staff', 'Test IT Staff', 'test.it.staff@example.test', $itDivision, 'staff');
            $this->syncSyntheticUser('hr-staff', 'Test HR Staff', 'test.hr.staff@example.test', $hrDivision, 'staff');
            $this->syncSyntheticUser('hr-head', 'Test HR Head', 'test.hr.head@example.test', $hrDivision, 'division_head');
        });
    }

    private function syncSyntheticDivision(string $key, string $name, string $code): Division
    {
        $ssoDivisionId = self::SSO_ID_PREFIX.$key;
        $division = Division::query()->where('sso_division_id', $ssoDivisionId)->first();
        $codeOwner = Division::query()->where('code', $code)->where('sso_division_id', '!=', $ssoDivisionId)->first();

        if ($codeOwner !== null) {
            throw new RuntimeException("The synthetic division code {$code} already belongs to another division.");
        }

        $division ??= new Division;
        $division->forceFill([
            'sso_division_id' => $ssoDivisionId,
            'name' => $name,
            'code' => $code,
            'is_active' => true,
            'last_synced_at' => null,
        ])->save();

        return $division;
    }

    private function syncSyntheticUser(
        string $key,
        string $name,
        string $email,
        Division $division,
        string $role,
    ): void {
        $ssoUserId = self::SSO_ID_PREFIX.$key;
        $user = User::query()->where('sso_user_id', $ssoUserId)->first();
        $emailOwner = User::query()->where('email', $email)->first();

        if ($emailOwner !== null && $emailOwner->sso_user_id !== $ssoUserId) {
            throw new RuntimeException("The synthetic email {$email} already belongs to another account.");
        }

        if ($user !== null && ! str_ends_with($user->email, '@example.test')) {
            throw new RuntimeException("The synthetic SSO identifier {$ssoUserId} is already attached to a non-test account.");
        }

        $roleFlags = match ($role) {
            'staff' => ['is_admin' => false, 'is_division_head' => false],
            'division_head' => ['is_admin' => false, 'is_division_head' => true],
            default => throw new RuntimeException("Unsupported synthetic document tracking role: {$role}."),
        };

        $attributes = [
            'name' => $name,
            'email' => $email,
            'division_id' => $division->id,
            'is_active' => true,
            'last_synced_at' => null,
            'updated_at' => now(),
        ] + $roleFlags;

        if ($user !== null) {
            DB::table('users')->where('id', $user->id)->update($attributes);

            return;
        }

        DB::table('users')->insert($attributes + [
            'sso_user_id' => $ssoUserId,
            'email_verified_at' => null,
            'password' => '!',
            'remember_token' => null,
            'created_at' => now(),
        ]);
    }
}
