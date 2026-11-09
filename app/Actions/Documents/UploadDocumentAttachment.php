<?php

namespace App\Actions\Documents;

use App\Models\Document;
use App\Models\DocumentAttachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\File;
use RuntimeException;
use Throwable;

class UploadDocumentAttachment
{
    public function handle(Document $document, UploadedFile $file): DocumentAttachment
    {
        Gate::authorize('uploadAttachment', $document);

        Validator::make(['file' => $file], [
            'file' => [
                'required',
                File::types(config('documents.attachment_types'))
                    ->max(config('documents.attachment_max_upload_kb')),
            ],
        ])->validate();

        $disk = config('documents.disk');
        $filename = Str::uuid().'.'.strtolower($file->extension());
        $path = Storage::disk($disk)->putFileAs("documents/{$document->id}/attachments", $file, $filename);

        if ($path === false) {
            throw new RuntimeException('The attachment could not be stored.');
        }

        try {
            return DB::transaction(function () use ($document, $file, $disk, $path): DocumentAttachment {
                $lockedDocument = Document::query()->lockForUpdate()->findOrFail($document->id);
                Gate::authorize('uploadAttachment', $lockedDocument);

                $attachment = $lockedDocument->attachments()->create([
                    'uploaded_by' => auth()->id(),
                    'storage_path' => $path,
                    'disk' => $disk,
                    'original_filename' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType() ?? $file->getClientMimeType(),
                    'file_size' => $file->getSize(),
                ]);

                $lockedDocument->actions()->create([
                    'performed_by' => auth()->id(),
                    'action' => 'attachment_uploaded',
                    'metadata' => ['document_attachment_id' => $attachment->id],
                ]);

                return $attachment;
            });
        } catch (Throwable $exception) {
            Storage::disk($disk)->delete($path);

            throw $exception;
        }
    }
}
