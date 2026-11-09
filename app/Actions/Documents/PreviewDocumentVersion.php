<?php

namespace App\Actions\Documents;

use App\Models\DocumentVersion;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PreviewDocumentVersion
{
    public function handle(DocumentVersion $version): StreamedResponse
    {
        Gate::authorize('download', $version->document);

        abort_unless($version->mime_type === 'application/pdf', 415, 'Only PDF files can be previewed.');
        abort_unless(Storage::disk($version->disk)->exists($version->storage_path), 404);

        return Storage::disk($version->disk)->response(
            $version->storage_path,
            $version->original_filename,
            [
                'Content-Type' => 'application/pdf',
                'Cache-Control' => 'private, no-store',
                'Content-Security-Policy' => "sandbox; default-src 'none'",
                'X-Content-Type-Options' => 'nosniff',
                'X-Frame-Options' => 'SAMEORIGIN',
            ],
            'inline',
        );
    }
}
