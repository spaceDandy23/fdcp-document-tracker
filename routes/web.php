<?php

use App\Actions\Documents\DownloadDocumentAttachment;
use App\Actions\Documents\DownloadDocumentVersion;
use App\Actions\Documents\PreviewDocumentAttachment;
use App\Actions\Documents\PreviewDocumentVersion;
use App\Http\Controllers\FdcpAuthenticationController;
use App\Http\Controllers\ReportExportController;
use App\Models\Document;
use App\Models\DocumentAttachment;
use App\Models\DocumentVersion;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [FdcpAuthenticationController::class, 'redirect'])->name('login');
    Route::get('/auth/callback', [FdcpAuthenticationController::class, 'callback'])
        ->name('auth.fdcp.callback');
});

Route::middleware('auth')->group(function (): void {
    Route::livewire('/', 'pages::dashboard')->name('home');
    Route::livewire('/documents', 'pages::documents.index')->name('documents.index');
    Route::livewire('/documents/create', 'pages::documents.create')->name('documents.create');
    Route::livewire('/documents/{document}', 'pages::documents.show')->name('documents.show');
    Route::get('/documents/{document}/versions/{version}/download', function (Document $document, DocumentVersion $version, DownloadDocumentVersion $download) {
        abort_unless($version->document_id === $document->id, 404);

        return $download->handle($version);
    })->name('documents.versions.download');
    Route::get('/documents/{document}/versions/{version}/preview', function (Document $document, DocumentVersion $version, PreviewDocumentVersion $preview) {
        abort_unless($version->document_id === $document->id, 404);

        return $preview->handle($version);
    })->name('documents.versions.preview');
    Route::get('/documents/{document}/attachments/{attachment}/download', function (Document $document, DocumentAttachment $attachment, DownloadDocumentAttachment $download) {
        abort_unless($attachment->document_id === $document->id, 404);

        return $download->handle($attachment);
    })->name('documents.attachments.download');
    Route::get('/documents/{document}/attachments/{attachment}/preview', function (Document $document, DocumentAttachment $attachment, PreviewDocumentAttachment $preview) {
        abort_unless($attachment->document_id === $document->id, 404);

        return $preview->handle($attachment);
    })->name('documents.attachments.preview');
    Route::livewire('/inbox', 'pages::inbox')->name('inbox');
    Route::livewire('/outgoing', 'pages::outgoing')->name('outgoing');
    Route::livewire('/division', 'pages::division')->name('division');
    Route::livewire('/reports', 'pages::reports')->name('reports');
    Route::get('/reports/export/documents', [ReportExportController::class, 'documents'])->name('reports.export.documents');
    Route::get('/reports/export/divisions', [ReportExportController::class, 'divisions'])->name('reports.export.divisions');
    Route::get('/reports/export/aging', [ReportExportController::class, 'aging'])->name('reports.export.aging');
    Route::livewire('/administration/documents', 'pages::admin.documents')->name('admin.documents');
    Route::livewire('/administration/document-types', 'pages::admin.document-types')->name('admin.document-types');
    Route::post('/logout', [FdcpAuthenticationController::class, 'logout'])->name('logout');
});
