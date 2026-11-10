<?php

namespace App\Actions\Documents;

use App\Models\Document;
use App\Models\User;
use App\Notifications\DocumentWorkflowNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class NotifyDocumentWorkflow
{
    public function handle(User $recipient, Document $document, string $eventType, string $message): void
    {
        $recipientId = $recipient->getKey();
        $documentId = $document->getKey();
        $trackingNumber = $document->tracking_number;

        DB::afterCommit(static function () use ($recipientId, $documentId, $trackingNumber, $eventType, $message): void {
            try {
                $recipient = User::query()->find($recipientId);

                if ($recipient !== null) {
                    $recipient->notify(new DocumentWorkflowNotification($eventType, $documentId, $trackingNumber, $message));
                }
            } catch (Throwable $exception) {
                Log::warning('Document workflow notification delivery failed.', [
                    'recipient_id' => $recipientId,
                    'document_id' => $documentId,
                    'event_type' => $eventType,
                    'failure_category' => $exception::class,
                ]);
            }
        });
    }
}
