<?php

use App\Models\Document;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public bool $isOpen = false;

    public string $statusMessage = '';

    #[Computed]
    public function unreadCount(): int
    {
        return auth()->user()->unreadNotifications()->count();
    }

    #[Computed]
    public function recentNotifications(): array
    {
        $notifications = auth()->user()->notifications()->latest()->limit(8)->get();
        $documents = Document::query()
            ->whereKey($notifications->pluck('data.document_id')->filter()->unique())
            ->get()
            ->keyBy('id');

        return $notifications->map(function (DatabaseNotification $notification) use ($documents): array {
            $document = $documents->get($notification->data['document_id'] ?? null);
            $accessible = $document !== null && Gate::allows('view', $document);

            return [
                'id' => $notification->id,
                'read' => $notification->read_at !== null,
                'message' => $accessible
                    ? ($notification->data['message'] ?? 'Document activity update.')
                    : 'A document notification is no longer accessible.',
                'tracking_number' => $accessible ? ($notification->data['tracking_number'] ?? $document->tracking_number) : null,
                'created_at' => $notification->created_at?->diffForHumans(),
                'accessible' => $accessible,
            ];
        })->all();
    }

    public function toggle(): void
    {
        $this->isOpen = ! $this->isOpen;
        $this->statusMessage = '';
        unset($this->unreadCount, $this->recentNotifications);
    }

    public function close(): void
    {
        $this->isOpen = false;
    }

    public function markAsRead(string $notificationId): void
    {
        $notification = auth()->user()->notifications()->whereKey($notificationId)->firstOrFail();
        if (! $this->notificationDocumentIsAccessible($notification)) {
            $this->statusMessage = 'This document is no longer available to you.';
            unset($this->recentNotifications);

            return;
        }

        $notification->markAsRead();
        $this->statusMessage = 'Notification marked as read.';
        unset($this->unreadCount, $this->recentNotifications);
    }

    public function markAllAsRead(): void
    {
        auth()->user()->unreadNotifications()->update(['read_at' => now()]);
        $this->statusMessage = 'All notifications marked as read.';
        unset($this->unreadCount, $this->recentNotifications);
    }

    public function openNotification(string $notificationId): mixed
    {
        $notification = auth()->user()->notifications()->whereKey($notificationId)->firstOrFail();
        $document = Document::query()->find($notification->data['document_id'] ?? null);

        if ($document === null || ! Gate::allows('view', $document)) {
            $this->statusMessage = 'This document is no longer available to you.';
            unset($this->recentNotifications);

            return null;
        }

        $notification->markAsRead();

        return $this->redirectRoute('documents.show', ['document' => $document], navigate: true);
    }

    private function notificationDocumentIsAccessible(DatabaseNotification $notification): bool
    {
        $document = Document::query()->find($notification->data['document_id'] ?? null);

        return $document !== null && Gate::allows('view', $document);
    }
};
?>

<div class="relative" wire:poll.60s>
    <button type="button" wire:click="toggle" aria-label="Notifications, {{ $this->unreadCount }} unread" aria-expanded="{{ $isOpen ? 'true' : 'false' }}" aria-controls="notification-menu" class="relative inline-flex size-10 items-center justify-center rounded-lg border border-slate-300 text-slate-700 transition hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-fdcp-700">
        <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="size-5"><path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.4-1.4A2 2 0 0 1 18 14.2V11a6 6 0 0 0-5-5.9V4a1 1 0 0 0-2 0v1.1A6 6 0 0 0 6 11v3.2a2 2 0 0 1-.6 1.4L4 17h5m6 0a3 3 0 0 1-6 0m6 0H9"/></svg>
        @if ($this->unreadCount > 0)<span class="absolute -right-1 -top-1 min-w-5 rounded-full bg-red-700 px-1 text-center text-xs font-semibold leading-5 text-white">{{ $this->unreadCount > 99 ? '99+' : $this->unreadCount }}</span>@endif
    </button>

    @if ($isOpen)
        <section id="notification-menu" role="region" aria-label="Recent notifications" wire:keydown.escape.window="close" wire:click.outside="close" class="absolute right-0 z-50 mt-2 w-80 max-w-[calc(100vw-2rem)] overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xl sm:w-96">
            <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
                <h2 class="font-semibold text-slate-900">Notifications</h2>
                @if ($this->unreadCount > 0)<button type="button" wire:click="markAllAsRead" class="text-sm font-medium text-fdcp-700 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2">Mark all as read</button>@endif
            </div>
            @if ($statusMessage)<p role="status" class="border-b border-slate-100 px-4 py-2 text-sm text-slate-600">{{ $statusMessage }}</p>@endif
            @if (count($this->recentNotifications) === 0)
                <p class="px-4 py-8 text-center text-sm text-slate-500">You’re all caught up. No notifications yet.</p>
            @else
                <ul class="max-h-[min(70dvh,28rem)] divide-y divide-slate-100 overflow-y-auto">
                    @foreach ($this->recentNotifications as $notification)
                        <li wire:key="notification-{{ $notification['id'] }}" class="{{ $notification['read'] ? 'bg-white' : 'bg-sky-50/70' }}">
                            <div class="flex items-start gap-3 px-4 py-3">
                                <span aria-label="{{ $notification['read'] ? 'Read' : 'Unread' }}" class="mt-1.5 size-2 shrink-0 rounded-full {{ $notification['read'] ? 'bg-transparent' : 'bg-fdcp-700' }}"></span>
                                <div class="min-w-0 flex-1">
                                    @if ($notification['accessible'])
                                        <button type="button" wire:click="openNotification('{{ $notification['id'] }}')" class="block w-full text-left text-sm font-medium text-slate-900 hover:text-fdcp-700 focus-visible:underline">{{ $notification['message'] }}</button>
                                    @else
                                        <p class="text-sm text-slate-700">{{ $notification['message'] }}</p>
                                    @endif
                                    <p class="mt-1 text-xs text-slate-500">{{ $notification['tracking_number'] ? $notification['tracking_number'].' · ' : '' }}{{ $notification['created_at'] }}</p>
                                    @if (! $notification['read'] && $notification['accessible'])<button type="button" wire:click="markAsRead('{{ $notification['id'] }}')" class="mt-1 text-xs font-medium text-fdcp-700 hover:underline">Mark as read</button>@endif
                                </div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    @endif
</div>
