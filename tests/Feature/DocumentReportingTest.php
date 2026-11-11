<?php

namespace Tests\Feature;

use App\Models\Division;
use App\Models\Document;
use App\Models\DocumentAction;
use App\Models\DocumentRoute;
use App\Models\DocumentType;
use App\Models\DocumentVersion;
use App\Models\User;
use App\Reports\DocumentReportQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class DocumentReportingTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_counts_exclude_drafts_and_calculate_completed_processing_time(): void
    {
        Carbon::setTestNow('2026-11-10 12:00:00');
        $admin = $this->employee(['is_admin' => true]);
        $type = $this->type();
        $this->document($admin, $type, ['status' => 'draft']);
        $this->document($admin, $type, ['status' => 'pending_acknowledgment', 'submitted_at' => now()->subDay()]);
        $this->document($admin, $type, ['status' => 'completed', 'submitted_at' => now()->subDays(3), 'completed_at' => now()->subDay(), 'current_handler_id' => null]);
        $this->actingAs($admin);

        $summary = app(DocumentReportQuery::class)->summary();

        $this->assertSame(2, $summary['total_submitted']);
        $this->assertSame(1, $summary['pending_acknowledgment']);
        $this->assertSame(1, $summary['completed']);
        $this->assertSame(172800.0, $summary['average_processing_seconds']);
    }

    public function test_division_metrics_attribute_cross_division_routes_without_double_counting_documents(): void
    {
        $origin = $this->division('ORIGIN');
        $receiver = $this->division('RECEIVE');
        $admin = $this->employee(['division_id' => $origin->id, 'is_admin' => true]);
        $handler = $this->employee(['division_id' => $receiver->id]);
        $document = $this->document($admin, $this->type(), ['status' => 'in_progress', 'submitted_at' => now()->subDay(), 'current_handler_id' => $handler->id]);
        $this->route($document, $admin, $handler, 'initial');
        $this->route($document, $handler, $admin, 'forward');
        DocumentAction::query()->create(['document_id' => $document->id, 'performed_by' => $handler->id, 'action' => 'completed']);
        $this->actingAs($admin);

        $metrics = app(DocumentReportQuery::class)->divisionMetrics()->keyBy('id');

        $this->assertSame(1, (int) $metrics[$origin->id]->originated);
        $this->assertSame(1, (int) $metrics[$receiver->id]->received);
        $this->assertSame(1, (int) $metrics[$receiver->id]->forwarded);
        $this->assertSame(1, (int) $metrics[$receiver->id]->completed);
        $this->assertSame(1, (int) $metrics[$receiver->id]->assigned);
    }

    public function test_division_head_reports_only_documents_in_their_existing_visibility_scope(): void
    {
        $division = $this->division('HEAD');
        $otherDivision = $this->division('OTHER');
        $head = $this->employee(['division_id' => $division->id, 'is_division_head' => true]);
        $other = $this->employee(['division_id' => $otherDivision->id]);
        $this->document($head, $this->type(), ['title' => 'Visible document', 'submitted_at' => now(), 'status' => 'in_progress']);
        $this->document($other, $this->type('Letter'), ['title' => 'Hidden document', 'submitted_at' => now(), 'status' => 'in_progress']);
        $this->actingAs($head);

        Livewire::test('pages::reports')->assertSee('Visible document')->assertDontSee('Hidden document');
        $export = $this->get(route('reports.export.documents'))->assertOk();
        $this->assertStringContainsString('Visible document', $export->streamedContent());
        $this->assertStringNotContainsString('Hidden document', $export->streamedContent());
        $this->assertSame(1, app(DocumentReportQuery::class)->documents()->count());
    }

    public function test_staff_cannot_access_reports_or_exports(): void
    {
        $staff = $this->employee();

        $this->actingAs($staff)->get(route('reports'))->assertForbidden();
        $this->get(route('reports.export.documents'))->assertForbidden();
        $this->get(route('reports.export.divisions'))->assertForbidden();
        $this->get(route('reports.export.aging'))->assertForbidden();
    }

    public function test_filters_include_custom_document_types_and_aging_buckets_follow_boundaries(): void
    {
        Carbon::setTestNow('2026-11-10 12:00:00');
        $admin = $this->employee(['is_admin' => true]);
        $document = $this->document($admin, $this->type(), ['document_type_id' => null, 'custom_document_type' => 'External endorsement', 'status' => 'in_progress', 'submitted_at' => now()->subDays(7)]);
        DocumentAction::query()->forceCreate(['document_id' => $document->id, 'performed_by' => $admin->id, 'action' => 'forwarded', 'created_at' => now()->subDays(7)]);
        $this->actingAs($admin);

        $reports = app(DocumentReportQuery::class);
        $this->assertSame(1, $reports->documents(['document_type' => 'custom'])->count());
        $this->assertSame('7–13 days', $reports->agingBucket($reports->agingDocuments()->sole()));
    }

    public function test_csv_exports_escape_spreadsheet_formulas_and_use_filter_scope(): void
    {
        $admin = $this->employee(['is_admin' => true]);
        $this->document($admin, $this->type(), ['title' => '=SUM(1,1)', 'status' => 'completed', 'submitted_at' => now(), 'completed_at' => now(), 'current_handler_id' => null]);

        $response = $this->actingAs($admin)->get(route('reports.export.documents'))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString("'=SUM(1,1)", $response->streamedContent());
    }

    public function test_csv_exports_escape_control_prefixed_formulas_and_preserve_normal_values(): void
    {
        $admin = $this->employee(['is_admin' => true]);
        $type = $this->type();
        $titles = [
            '  =SUM(1,1)',
            "\t+SUM(1,1)",
            "\r-CMD()",
            "\n@HYPERLINK(\"https://example.test\")",
            '  Ordinary memo',
        ];

        foreach ($titles as $title) {
            $this->document($admin, $type, [
                'title' => $title,
                'status' => 'completed',
                'submitted_at' => now(),
                'completed_at' => now(),
                'current_handler_id' => null,
            ]);
        }

        $csv = $this->actingAs($admin)->get(route('reports.export.documents'))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString("'  =SUM(1,1)", $csv);
        $this->assertStringContainsString("'\t+SUM(1,1)", $csv);
        $this->assertMatchesRegularExpression("/'\\s*-CMD\\(\\)/s", $csv);
        $this->assertMatchesRegularExpression("/'\\s*@HYPERLINK/s", $csv);
        $this->assertStringContainsString('  Ordinary memo', $csv);
    }

    public function test_aging_documents_are_paginated(): void
    {
        $admin = $this->employee(['is_admin' => true]);
        $type = $this->type();

        foreach (range(1, 16) as $number) {
            $this->document($admin, $type, [
                'title' => 'Aging document '.str_pad((string) $number, 2, '0', STR_PAD_LEFT),
                'status' => 'in_progress',
                'submitted_at' => now()->subDay(),
            ]);
        }

        Livewire::actingAs($admin)->test('pages::reports')
            ->assertSee('Aging document 01')
            ->assertDontSee('Aging document 16')
            ->assertSee('Next')
            ->call('setPage', 2, 'agingPage')
            ->assertSee('Aging document 16')
            ->assertDontSee('Aging document 01');
    }

    private function division(string $code): Division
    {
        return Division::query()->create(['sso_division_id' => 'report:'.$code, 'name' => 'Division '.$code, 'code' => $code, 'is_active' => true]);
    }

    private function employee(array $attributes = []): User
    {
        $division = $attributes['division_id'] ?? $this->division(fake()->unique()->lexify('???'));

        return User::factory()->create(array_merge(['division_id' => $division instanceof Division ? $division->id : $division], $attributes));
    }

    private function type(string $name = 'Memorandum'): DocumentType
    {
        return DocumentType::query()->create(['name' => $name, 'code' => fake()->unique()->lexify('TYPE-???'), 'is_active' => true]);
    }

    private function document(User $creator, DocumentType $type, array $attributes = []): Document
    {
        return Document::query()->create(array_merge(['tracking_number' => 'FDCP-'.fake()->unique()->numerify('########'), 'title' => 'Report document', 'document_type_id' => $type->id, 'origin_division_id' => $creator->division_id, 'created_by' => $creator->id, 'current_handler_id' => $creator->id, 'status' => 'draft'], $attributes));
    }

    private function route(Document $document, User $from, User $to, string $type): void
    {
        $version = DocumentVersion::query()->create(['document_id' => $document->id, 'version_number' => $document->versions()->count() + 1, 'uploaded_by' => $from->id, 'storage_path' => 'reports/file.pdf', 'disk' => 'local', 'original_filename' => 'file.pdf', 'mime_type' => 'application/pdf', 'file_size' => 1]);
        DocumentRoute::query()->create(['document_id' => $document->id, 'document_version_id' => $version->id, 'from_user_id' => $from->id, 'to_user_id' => $to->id, 'from_division_id' => $from->division_id, 'to_division_id' => $to->division_id, 'route_type' => $type, 'status' => 'acknowledged', 'forwarded_at' => now(), 'acknowledged_at' => now()]);
    }
}
