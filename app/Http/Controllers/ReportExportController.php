<?php

namespace App\Http\Controllers;

use App\Reports\DocumentReportQuery;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportExportController extends Controller
{
    public function documents(Request $request, DocumentReportQuery $reports): StreamedResponse
    {
        $filters = $this->filters($request);
        $reports->documents($filters);

        return response()->streamDownload(function () use ($reports, $filters): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Tracking number', 'Title', 'Document type', 'Status', 'Origin division', 'Submitted at', 'Completed at']);
            $reports->documents($filters)->with(['documentType:id,name', 'originDivision:id,name'])->orderBy('id')->lazyById(500)->each(function ($document) use ($output): void {
                fputcsv($output, $this->safeRow([
                    $document->tracking_number,
                    $document->title,
                    $document->document_type_label,
                    $document->status,
                    $document->originDivision?->name,
                    $document->submitted_at?->toDateTimeString(),
                    $document->completed_at?->toDateTimeString(),
                ]));
            });
            fclose($output);
        }, 'document-summary.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function divisions(Request $request, DocumentReportQuery $reports): StreamedResponse
    {
        $filters = $this->filters($request);
        $reports->documents($filters);

        return response()->streamDownload(function () use ($reports, $filters): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Division', 'Originated documents', 'Received documents', 'Forwarded documents', 'Completed documents', 'Currently assigned documents']);
            foreach ($reports->divisionMetrics($filters) as $division) {
                fputcsv($output, $this->safeRow([$division->name, $division->originated, $division->received, $division->forwarded, $division->completed, $division->assigned]));
            }
            fclose($output);
        }, 'division-activity.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function aging(Request $request, DocumentReportQuery $reports): StreamedResponse
    {
        $filters = $this->filters($request);
        $reports->documents($filters);

        return response()->streamDownload(function () use ($reports, $filters): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Tracking number', 'Title', 'Status', 'Current handler', 'Last workflow action', 'Aging bucket']);
            foreach ($reports->agingDocuments($filters) as $document) {
                fputcsv($output, $this->safeRow([$document->tracking_number, $document->title, $document->status, $document->currentHandler?->name, $document->last_workflow_at?->toDateTimeString(), $reports->agingBucket($document)]));
            }
            fclose($output);
        }, 'aging-documents.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        return $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'division_id' => ['nullable', 'integer', 'exists:divisions,id'],
            'document_type' => ['nullable', 'string'],
            'status' => ['nullable', 'in:'.implode(',', DocumentReportQuery::STATUSES)],
        ]);
    }

    /** @param array<int, mixed> $values
     * @return array<int, mixed>
     */
    private function safeRow(array $values): array
    {
        return array_map(static function (mixed $value): mixed {
            if (is_string($value) && preg_match('/^[=+\-@]/', $value) === 1) {
                return "'{$value}";
            }

            return $value;
        }, $values);
    }
}
