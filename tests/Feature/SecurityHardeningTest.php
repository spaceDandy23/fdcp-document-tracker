<?php

namespace Tests\Feature;

use App\Models\Division;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_existing_session_is_rejected_after_the_user_becomes_inactive(): void
    {
        $user = $this->employee();
        $document = $this->draft($user);
        $user->forceFill(['is_active' => false])->save();

        $response = $this->actingAs($user)->get(route('documents.show', $document));

        $response->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertFalse($user->fresh()->can('view', $document));
    }

    public function test_an_inactive_session_cannot_access_reports_or_exports(): void
    {
        $user = $this->employee(['is_admin' => true]);
        $user->forceFill(['is_active' => false])->save();

        $this->actingAs($user)->get(route('home'))->assertRedirect(route('login'));
        $this->actingAs($user)->get(route('reports'))->assertRedirect(route('login'));
        $this->actingAs($user)->get(route('reports.export.documents'))->assertRedirect(route('login'));
    }

    public function test_default_database_seeder_does_not_create_a_local_password_account(): void
    {
        config(['app.env' => 'production']);

        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseMissing('users', ['email' => 'test@example.com']);
        $this->assertDatabaseCount('users', 0);
    }

    private function employee(array $attributes = []): User
    {
        $division = Division::query()->create([
            'sso_division_id' => fake()->uuid(),
            'name' => fake()->company(),
            'code' => fake()->unique()->lexify('DIV-???'),
            'is_active' => true,
        ]);

        return User::factory()->create(array_merge(['division_id' => $division->id], $attributes));
    }

    private function draft(User $creator): Document
    {
        $type = DocumentType::query()->create([
            'name' => 'Memorandum',
            'code' => fake()->unique()->lexify('MEM-???'),
            'is_active' => true,
        ]);

        return Document::query()->create([
            'tracking_number' => null,
            'title' => 'Security test document',
            'document_type_id' => $type->id,
            'origin_division_id' => $creator->division_id,
            'created_by' => $creator->id,
            'current_handler_id' => $creator->id,
            'status' => 'draft',
        ]);
    }
}
