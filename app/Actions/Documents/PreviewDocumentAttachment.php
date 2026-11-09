<?php

namespace App\Actions\Documents;

use App\Models\DocumentAttachment;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PreviewDocumentAttachment
{
    public function handle(DocumentAttachment $attachment): StreamedResponse
    {
        Gate::authorize('download', $attachment->document);

        abort_unless($attachment->mime_type === 'application/pdf', 415, 'Only PDF files can be previewed.');
        abort_unless(Storage::disk($attachment->disk)->exists($attachment->storage_path), 404);

        return Storage::disk($attachment->disk)->response(
            $attachment->storage_path,
            $attachment->original_filename,
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
