<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class DocumentWorkflowNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $eventType,
        public readonly int $documentId,
        public readonly ?string $trackingNumber,
        public readonly string $message,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function databaseType(object $notifiable): string
    {
        return 'document_workflow';
    }

    /** @return array{event_type: string, document_id: int, tracking_number: ?string, message: string} */
    public function toDatabase(object $notifiable): array
    {
        return [
            'event_type' => $this->eventType,
            'document_id' => $this->documentId,
            'tracking_number' => $this->trackingNumber,
            'message' => $this->message,
        ];
    }
}
