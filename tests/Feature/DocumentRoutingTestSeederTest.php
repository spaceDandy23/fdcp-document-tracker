<?php

namespace Tests\Feature;

use App\Models\Division;
use App\Models\User;
use Database\Seeders\DocumentRoutingTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class DocumentRoutingTestSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_seeder_creates_three_synthetic_role_accounts_idempotently_without_changing_real_records(): void
    {
        $this->app['env'] = 'local';

        $realDivision = Division::query()->create([
            'sso_division_id' => 'fdcp-real-division-hr',
            'name' => 'Human Resources',
            'code' => 'HR',
            'is_active' => true,
        ]);
        $realEmployee = User::factory()->create([
            'name' => 'Real HR Employee',
            'email' => 'real.hr@fdcp.gov.ph',
            'sso_user_id' => 'fdcp-real-user-hr',
            'division_id' => $realDivision->id,
            'is_admin' => true,
            'is_division_head' => false,
        ]);

        $seeder = app(DocumentRoutingTestSeeder::class);
        $seeder->run();
        $seeder->run();

        $itStaff = User::query()->where('sso_user_id', 'dev-test:document-routing:it-staff')->sole();
        $hrStaff = User::query()->where('sso_user_id', 'dev-test:document-routing:hr-staff')->sole();
        $hrHead = User::query()->where('sso_user_id', 'dev-test:document-routing:hr-head')->sole();

        $this->assertSame('Test IT Staff', $itStaff->name);
        $this->assertSame('test.it.staff@example.test', $itStaff->email);
        $this->assertTrue($itStaff->is_active);
        $this->assertFalse($itStaff->is_admin);
        $this->assertFalse($itStaff->is_division_head);
        $this->assertSame('IT Section', $itStaff->division->name);

        $this->assertSame('Test HR Staff', $hrStaff->name);
        $this->assertSame('test.hr.staff@example.test', $hrStaff->email);
        $this->assertTrue($hrStaff->is_active);
        $this->assertFalse($hrStaff->is_admin);
        $this->assertFalse($hrStaff->is_division_head);
        $this->assertSame('HR', $hrStaff->division->name);

        $this->assertSame('Test HR Head', $hrHead->name);
        $this->assertSame('test.hr.head@example.test', $hrHead->email);
        $this->assertTrue($hrHead->is_active);
        $this->assertFalse($hrHead->is_admin);
        $this->assertTrue($hrHead->is_division_head);
        $this->assertSame($hrStaff->division_id, $hrHead->division_id);

        $this->assertSame('!', $itStaff->getAuthPassword());
        $this->assertSame(3, User::query()->where('sso_user_id', 'like', 'dev-test:document-routing:%')->count());
        $this->assertSame(2, Division::query()->where('sso_division_id', 'like', 'dev-test:document-routing:%')->count());
        $this->assertDatabaseCount('documents', 0);
        $this->assertDatabaseCount('document_routes', 0);
        $this->assertSame('Real HR Employee', $realEmployee->fresh()->name);
        $this->assertTrue($realEmployee->fresh()->is_admin);
        $this->assertSame('Human Resources', $realDivision->fresh()->name);
    }

    public function test_seeder_refuses_to_run_outside_the_local_environment(): void
    {
        $this->app['env'] = 'testing';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('only be run in the local environment');

        app(DocumentRoutingTestSeeder::class)->run();
    }

    public function test_seeder_does_not_overwrite_an_account_with_a_conflicting_synthetic_email(): void
    {
        $this->app['env'] = 'local';
        $division = Division::query()->create([
            'sso_division_id' => 'fdcp-real-division-it',
            'name' => 'Information Technology',
            'code' => 'IT',
            'is_active' => true,
        ]);
        $realEmployee = User::factory()->create([
            'name' => 'Real Employee',
            'email' => 'test.it.staff@example.test',
            'sso_user_id' => 'fdcp-real-user-it',
            'division_id' => $division->id,
        ]);

        try {
            app(DocumentRoutingTestSeeder::class)->run();
            $this->fail('Expected a conflicting email to stop seeding.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('already belongs to another account', $exception->getMessage());
        }

        $this->assertSame('Real Employee', $realEmployee->fresh()->name);
        $this->assertSame('fdcp-real-user-it', $realEmployee->fresh()->sso_user_id);
        $this->assertSame(1, User::query()->count());
        $this->assertSame(1, Division::query()->count());
        $this->assertSame('Information Technology', $division->fresh()->name);
    }

    public function test_seeder_does_not_reuse_or_modify_a_real_division_with_a_test_code(): void
    {
        $this->app['env'] = 'local';
        $realDivision = Division::query()->create([
            'sso_division_id' => 'fdcp-real-division-test-it',
            'name' => 'Real Information Technology',
            'code' => 'TEST-IT',
            'is_active' => true,
        ]);

        try {
            app(DocumentRoutingTestSeeder::class)->run();
            $this->fail('Expected a real division using a synthetic code to stop seeding.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('already belongs to another division', $exception->getMessage());
        }

        $this->assertSame('Real Information Technology', $realDivision->fresh()->name);
        $this->assertSame(1, Division::query()->count());
        $this->assertSame(0, User::query()->count());
    }
}
