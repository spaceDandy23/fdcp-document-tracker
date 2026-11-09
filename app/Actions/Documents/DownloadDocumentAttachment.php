<?php

namespace App\Actions\Documents;

use App\Models\DocumentAttachment;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DownloadDocumentAttachment
{
    public function handle(DocumentAttachment $attachment): StreamedResponse
    {
        Gate::authorize('download', $attachment->document);

        return Storage::disk($attachment->disk)->download(
            $attachment->storage_path,
            $attachment->original_filename,
            ['Content-Type' => $attachment->mime_type],
        );
    }
}
