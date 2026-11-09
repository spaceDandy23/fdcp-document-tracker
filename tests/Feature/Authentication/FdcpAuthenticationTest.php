<?php

namespace Tests\Feature\Authentication;

use App\Models\Division;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use RuntimeException;
use Tests\TestCase;

class FdcpAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.fdcp_accounts.client_id' => 'document-tracker-client',
            'services.fdcp_accounts.client_secret' => 'client-secret',
            'services.fdcp_accounts.redirect' => 'http://localhost/auth/callback',
        ]);
    }

    public function test_login_redirect_uses_state_and_s256_pkce(): void
    {
        $response = $this->get('/login');

        $response->assertRedirect();

        $location = $response->headers->get('Location');
        $this->assertIsString($location);
        $this->assertStringStartsWith('https://sso.fdcp.ph/oauth/authorize?', $location);

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        $verifier = session('code_verifier');
        $expectedChallenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $this->assertSame('document-tracker-client', $query['client_id']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame(session('state'), $query['state']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame($expectedChallenge, $query['code_challenge']);
        $this->assertArrayNotHasKey('client_secret', $query);
        $this->assertArrayNotHasKey('code_verifier', $query);
    }

    public function test_callback_rejects_a_state_mismatch_before_contacting_sso(): void
    {
        $response = $this->withSession([
            'state' => 'expected-state',
            'code_verifier' => str_repeat('v', 96),
        ])->get('/auth/callback?code=authorization-code&state=wrong-state');

        $response->assertStatus(419);
        $response->assertSeeText('Authentication session expired');
        $this->assertGuest();
    }

    public function test_callback_synchronizes_the_user_and_division_and_authenticates_a_session(): void
    {
        Socialite::fake('fdcp_accounts', SocialiteUser::fake($this->userinfo()));

        $response = $this->get('/auth/callback');

        $response->assertRedirect('/');
        $this->assertAuthenticated();

        $division = Division::sole();
        $user = User::sole();

        $this->assertSame('code:PPD', $division->sso_division_id);
        $this->assertSame('PPD', $division->name);
        $this->assertSame('PPD', $division->code);
        $this->assertTrue($division->is_active);
        $this->assertSame('stable-user-id', $user->sso_user_id);
        $this->assertSame($division->id, $user->division_id);
        $this->assertSame('Employee Name', $user->name);
        $this->assertSame('employee@fdcp.ph', $user->email);
        $this->assertTrue($user->is_active);
        $this->assertTrue($user->is_division_head);
        $this->assertFalse($user->is_admin);
        $this->assertNotNull($user->last_synced_at);
        $this->assertNotSame('oauth-access-token', $user->password);
        $response->assertDontSee('oauth-access-token');
        $response->assertDontSee('client-secret');
    }

    public function test_callback_accepts_the_documented_top_level_userinfo_contract_without_claim_path_configuration(): void
    {
        config(['services.fdcp_accounts.userinfo_mapping' => []]);
        Socialite::fake('fdcp_accounts', SocialiteUser::fake([
            'sub' => 'stable-user-id',
            'name' => 'Employee Name',
            'email' => 'employee@fdcp.ph',
            'is_active' => true,
            'department' => 'PPD',
            'apps' => [
                'document_tracking' => ['role' => 'staff'],
            ],
        ]));

        $response = $this->get('/auth/callback');

        $response->assertRedirect('/');
        $this->assertAuthenticated();
        $this->assertSame('stable-user-id', User::sole()->sso_user_id);
        $this->assertSame('code:PPD', Division::sole()->sso_division_id);
    }

    public function test_successful_callback_regenerates_the_session_identifier(): void
    {
        Socialite::fake('fdcp_accounts', SocialiteUser::fake($this->userinfo()));
        $this->withSession(['before_login' => true]);
        $sessionIdBeforeLogin = session()->getId();

        $this->get('/auth/callback')->assertRedirect('/');

        $this->assertNotSame($sessionIdBeforeLogin, session()->getId());
        $this->assertTrue(session('before_login'));
    }

    public function test_sync_links_an_existing_local_user_by_email_without_changing_ownership_identity(): void
    {
        $existingUser = User::factory()->create([
            'email' => 'employee@fdcp.ph',
            'sso_user_id' => null,
        ]);

        Socialite::fake('fdcp_accounts', SocialiteUser::fake($this->userinfo()));

        $this->get('/auth/callback')->assertRedirect('/');

        $this->assertSame($existingUser->id, auth()->id());
        $this->assertSame(1, User::count());
        $this->assertSame('stable-user-id', $existingUser->fresh()->sso_user_id);
    }

    public function test_sync_reuses_an_existing_division_with_the_same_code(): void
    {
        $division = Division::query()->create([
            'sso_division_id' => 'legacy-division-identifier',
            'name' => 'Planning and Policy Division',
            'code' => 'PPD',
            'is_active' => true,
        ]);
        Socialite::fake('fdcp_accounts', SocialiteUser::fake($this->userinfo()));

        $this->get('/auth/callback')->assertRedirect('/');

        $this->assertSame(1, Division::count());
        $this->assertSame($division->id, User::sole()->division_id);
        $this->assertSame('code:PPD', $division->fresh()->sso_division_id);
        $this->assertSame('Planning and Policy Division', $division->fresh()->name);
    }

    public function test_sync_updates_an_existing_user_without_replacing_the_local_identity(): void
    {
        $division = Division::query()->create([
            'sso_division_id' => 'code:OLD',
            'name' => 'OLD',
            'code' => 'OLD',
            'is_active' => true,
        ]);
        $user = User::factory()->create([
            'sso_user_id' => 'stable-user-id',
            'division_id' => $division->id,
            'name' => 'Old Name',
            'email' => 'old@fdcp.gov.ph',
            'is_admin' => true,
        ]);
        Socialite::fake('fdcp_accounts', SocialiteUser::fake($this->userinfo([
            'name' => 'Juan Dela Cruz',
            'email' => 'user@fdcp.gov.ph',
            'apps.document_tracking.role' => 'staff',
        ])));

        $this->get('/auth/callback')->assertRedirect('/');

        $user->refresh();
        $this->assertSame(1, User::count());
        $this->assertSame('Juan Dela Cruz', $user->name);
        $this->assertSame('user@fdcp.gov.ph', $user->email);
        $this->assertSame('PPD', $user->division->code);
        $this->assertFalse($user->is_admin);
    }

    public function test_admin_role_grants_administrator_access_only(): void
    {
        Socialite::fake('fdcp_accounts', SocialiteUser::fake($this->userinfo([
            'apps.document_tracking.role' => 'admin',
        ])));

        $this->get('/auth/callback')->assertRedirect('/');

        $user = User::sole();
        $this->assertTrue($user->is_admin);
        $this->assertFalse($user->is_division_head);
    }

    public function test_division_head_role_grants_division_head_access_only(): void
    {
        Socialite::fake('fdcp_accounts', SocialiteUser::fake($this->userinfo([
            'apps.document_tracking.role' => 'division_head',
        ])));

        $this->get('/auth/callback')->assertRedirect('/');

        $user = User::sole();
        $this->assertFalse($user->is_admin);
        $this->assertTrue($user->is_division_head);
    }

    public function test_staff_role_grants_no_elevated_access(): void
    {
        Socialite::fake('fdcp_accounts', SocialiteUser::fake($this->userinfo([
            'apps.document_tracking.role' => 'staff',
        ])));

        $this->get('/auth/callback')->assertRedirect('/');

        $user = User::sole();
        $this->assertFalse($user->is_admin);
        $this->assertFalse($user->is_division_head);
    }

    public function test_role_slugs_are_case_sensitive(): void
    {
        Socialite::fake('fdcp_accounts', SocialiteUser::fake($this->userinfo([
            'apps.document_tracking.role' => 'Admin',
        ])));

        $response = $this->get('/auth/callback');

        $response->assertStatus(422);
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_missing_role_is_denied(): void
    {
        $claims = $this->userinfo();
        unset($claims['apps']['document_tracking']['role']);
        Socialite::fake('fdcp_accounts', SocialiteUser::fake($claims));

        $response = $this->get('/auth/callback');

        $response->assertStatus(422);
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_unknown_role_is_denied(): void
    {
        Socialite::fake('fdcp_accounts', SocialiteUser::fake($this->userinfo([
            'apps.document_tracking.role' => 'auditor',
        ])));

        $response = $this->get('/auth/callback');

        $response->assertStatus(422);
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_malformed_role_is_denied(): void
    {
        Socialite::fake('fdcp_accounts', SocialiteUser::fake($this->userinfo([
            'apps.document_tracking.role' => ['admin'],
        ])));

        $response = $this->get('/auth/callback');

        $response->assertStatus(422);
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_role_changes_are_synchronized_without_replacing_the_local_user(): void
    {
        Socialite::fake('fdcp_accounts', SocialiteUser::fake($this->userinfo([
            'apps.document_tracking.role' => 'admin',
        ])));
        $this->get('/auth/callback')->assertRedirect('/');
        $user = User::sole();
        $this->assertTrue($user->is_admin);

        auth()->logout();
        Socialite::fake('fdcp_accounts', SocialiteUser::fake($this->userinfo([
            'apps.document_tracking.role' => 'staff',
        ])));
        $this->get('/auth/callback')->assertRedirect('/');

        $this->assertSame(1, User::count());
        $this->assertSame($user->id, auth()->id());
        $this->assertFalse($user->fresh()->is_admin);
        $this->assertFalse($user->fresh()->is_division_head);
    }

    public function test_users_cannot_mass_assign_their_own_authorization_flags(): void
    {
        $user = User::factory()->create();

        $user->fill([
            'is_admin' => true,
            'is_division_head' => true,
        ])->save();

        $this->assertFalse($user->fresh()->is_admin);
        $this->assertFalse($user->fresh()->is_division_head);
    }

    public function test_callback_rejects_missing_division_information_without_partial_sync(): void
    {
        $claims = $this->userinfo();
        unset($claims['department']);
        Log::spy();
        Socialite::fake('fdcp_accounts', SocialiteUser::fake($claims));

        $response = $this->get('/auth/callback');

        $response->assertStatus(422);
        $response->assertSeeText('FDCP account information is incomplete or invalid');
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('divisions', 0);
        Log::shouldHaveReceived('warning')->once()->with('FDCP SSO userinfo rejected.', [
            'field' => 'department',
            'category' => 'missing',
        ]);
    }

    public function test_identity_conflict_rolls_back_the_division_sync(): void
    {
        User::factory()->create([
            'email' => 'employee@fdcp.ph',
            'sso_user_id' => 'different-sso-user',
        ]);
        Socialite::fake('fdcp_accounts', SocialiteUser::fake($this->userinfo()));

        $response = $this->get('/auth/callback');

        $response->assertStatus(422);
        $this->assertDatabaseCount('divisions', 0);
        $this->assertDatabaseMissing('users', ['sso_user_id' => 'stable-user-id']);
    }

    public function test_callback_rejects_missing_or_malformed_stable_identifiers(): void
    {
        foreach ([null, '', [], true] as $subject) {
            $claims = $this->userinfo(['sub' => $subject]);

            if ($subject === null) {
                unset($claims['sub']);
            }

            Socialite::fake('fdcp_accounts', SocialiteUser::fake($claims));
            $this->get('/auth/callback')->assertStatus(422);
            $this->assertDatabaseCount('users', 0);
        }
    }

    public function test_callback_rejects_empty_or_malformed_departments(): void
    {
        foreach (['', '   ', [], 123, false] as $department) {
            Socialite::fake('fdcp_accounts', SocialiteUser::fake($this->userinfo([
                'department' => $department,
            ])));
            $this->get('/auth/callback')->assertStatus(422);
            $this->assertDatabaseCount('users', 0);
            $this->assertDatabaseCount('divisions', 0);
        }
    }

    public function test_callback_rejects_invalid_required_profile_claims(): void
    {
        $invalidClaims = [
            ['name' => ''],
            ['name' => []],
            ['email' => 'not-an-email'],
            ['email' => 123],
            ['is_active' => 'true'],
            ['is_active' => 1],
        ];

        foreach ($invalidClaims as $overrides) {
            Socialite::fake('fdcp_accounts', SocialiteUser::fake($this->userinfo($overrides)));
            $this->get('/auth/callback')->assertStatus(422);
            $this->assertDatabaseCount('users', 0);
            $this->assertDatabaseCount('divisions', 0);
        }
    }

    public function test_callback_rejects_an_inactive_sso_user(): void
    {
        Socialite::fake('fdcp_accounts', SocialiteUser::fake($this->userinfo([
            'is_active' => false,
            'apps.document_tracking.role' => 'staff',
        ])));

        $response = $this->get('/auth/callback');

        $response->assertForbidden();
        $this->assertGuest();
        $this->assertFalse(User::sole()->is_active);
    }

    public function test_callback_handles_sso_downtime_without_exposing_exception_details(): void
    {
        Socialite::fake('fdcp_accounts', function (): never {
            throw new RuntimeException('Provider failed with secret oauth-access-token');
        });

        $response = $this->get('/auth/callback');

        $response->assertStatus(503);
        $response->assertSeeText('FDCP Accounts is temporarily unavailable');
        $response->assertDontSee('oauth-access-token');
        $response->assertDontSee('Provider failed');
        $this->assertGuest();
    }

    public function test_application_routes_require_authentication(): void
    {
        $this->withoutVite();

        $this->get('/')->assertRedirect('/login');

        $this->actingAs(User::factory()->create())
            ->get('/')
            ->assertOk();
    }

    public function test_logout_clears_the_local_session_and_redirects_to_fdcp_sso_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $response->assertRedirect('https://sso.fdcp.ph/logout/sso');
        $this->assertGuest();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function userinfo(array $overrides = []): array
    {
        $claims = [
            'sub' => 'stable-user-id',
            'name' => 'Employee Name',
            'email' => 'employee@fdcp.ph',
            'is_active' => true,
            'department' => 'PPD',
            'apps' => [
                'document_tracking' => [
                    'role' => 'division_head',
                ],
            ],
            'token' => 'oauth-access-token',
            'refreshToken' => 'oauth-refresh-token',
        ];

        foreach ($overrides as $path => $value) {
            data_set($claims, $path, $value);
        }

        return $claims;
    }
}
