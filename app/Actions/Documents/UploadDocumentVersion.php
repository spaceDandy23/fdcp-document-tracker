<?php

namespace App\Actions\Documents;

use App\Models\Document;
use App\Models\DocumentVersion;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\File;
use RuntimeException;
use Throwable;

class UploadDocumentVersion
{
    public function handle(Document $document, UploadedFile $file, ?string $changeSummary = null): DocumentVersion
    {
        Gate::authorize('uploadVersion', $document);

        Validator::make(['file' => $file], [
            'file' => ['required', File::types(['pdf', 'docx'])->max(config('documents.max_upload_kb'))],
        ])->validate();

        $disk = config('documents.disk');
        $filename = Str::uuid().'.'.strtolower($file->extension());
        $path = Storage::disk($disk)->putFileAs("documents/{$document->id}/versions", $file, $filename);

        if ($path === false) {
            throw new RuntimeException('The document file could not be stored.');
        }

        try {
            return DB::transaction(function () use ($document, $file, $changeSummary, $disk, $path): DocumentVersion {
                $lockedDocument = Document::query()->lockForUpdate()->findOrFail($document->id);
                Gate::authorize('uploadVersion', $lockedDocument);
                $version = $lockedDocument->versions()->create([
                    'version_number' => ((int) $lockedDocument->versions()->max('version_number')) + 1,
                    'uploaded_by' => auth()->id(),
                    'storage_path' => $path,
                    'disk' => $disk,
                    'original_filename' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType() ?? $file->getClientMimeType(),
                    'file_size' => $file->getSize(),
                    'change_summary' => $changeSummary,
                ]);
                $lockedDocument->update(['current_version_id' => $version->id]);
                $lockedDocument->actions()->create([
                    'performed_by' => auth()->id(),
                    'action' => 'version_uploaded',
                    'metadata' => [
                        'document_version_id' => $version->id,
                        'version_number' => $version->version_number,
                    ],
                ]);

                return $version;
            });
        } catch (Throwable $exception) {
            Storage::disk($disk)->delete($path);

            throw $exception;
        }
    }
}
