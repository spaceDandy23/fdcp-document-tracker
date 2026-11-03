<?php

namespace App\Actions\Documents;

use App\Models\DocumentVersion;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DownloadDocumentVersion
{
    public function handle(DocumentVersion $version): StreamedResponse
    {
        Gate::authorize('download', $version->document);

        return Storage::disk($version->disk)->download(
            $version->storage_path,
            $version->original_filename,
            ['Content-Type' => $version->mime_type],
        );
    }
}
