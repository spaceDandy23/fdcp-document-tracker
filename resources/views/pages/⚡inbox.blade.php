<?php
use App\Actions\Documents\AcknowledgeDocumentRoute;
use App\Actions\Documents\DeclineDocumentRoute;
use App\Models\Document;
use App\Models\DocumentRoute;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Inbox')] class extends Component {
    public array $declineReasons = [];

    #[Computed]
    public function documents()
    {
        return Document::query()->where('current_handler_id', auth()->id())
            ->whereIn('status', ['pending_acknowledgment', 'in_progress', 'revision_requested'])
            ->with(['documentType:id,name', 'creator:id,name', 'routes' => fn ($query) => $query->where('to_user_id', auth()->id())->latest('id')])
            ->latest('updated_at')->get();
    }

    public function acknowledge(int $routeId, AcknowledgeDocumentRoute $acknowledge): void
    {
        $route = DocumentRoute::query()->where('to_user_id', auth()->id())->findOrFail($routeId);
        $acknowledge->handle($route);
        unset($this->documents);
        session()->flash('success', 'Document acknowledged.');
    }

    public function decline(int $routeId, DeclineDocumentRoute $decline): void
    {
        $route = DocumentRoute::query()->where('to_user_id', auth()->id())->findOrFail($routeId);
        $decline->handle($route, ['reason' => $this->declineReasons[$routeId] ?? null]);
        unset($this->documents);
        session()->flash('success', 'Document declined and returned to the sender.');
    }
};
?>
<div>
    <x-page-heading title="Inbox" description="Documents currently assigned to you." />
    <div class="space-y-4">@forelse($this->documents as $document)
        @php($pendingRoute = $document->status === 'pending_acknowledgment' ? $document->routes->firstWhere('status', 'pending') : null)
        <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm" wire:key="inbox-{{ $document->id }}">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"><div><a wire:navigate href="{{ route('documents.show', $document) }}" class="text-lg font-semibold text-fdcp-700 hover:underline">{{ $document->title }}</a><p class="text-sm text-slate-500">{{ $document->tracking_number ?? 'Draft' }} · {{ $document->documentType->name }}</p></div><x-status-badge :status="$document->status" /></div>
            @if($pendingRoute)<div class="mt-4 grid gap-3 border-t border-slate-100 pt-4 sm:grid-cols-[1fr_auto_auto]"><label><span class="sr-only">Decline reason</span><input wire:model="declineReasons.{{ $pendingRoute->id }}" placeholder="Reason required to decline" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"><x-form-error name="reason" /></label><button wire:click="decline({{ $pendingRoute->id }})" wire:confirm="Decline this document?" class="rounded-lg border border-red-300 px-4 py-2 text-sm font-semibold text-red-700">Decline</button><button wire:click="acknowledge({{ $pendingRoute->id }})" wire:confirm="Acknowledge responsibility for this document?" class="rounded-lg bg-fdcp-700 px-4 py-2 text-sm font-semibold text-white">Acknowledge</button></div>@endif
        </article>
    @empty<div class="rounded-2xl border border-dashed border-slate-300 p-12 text-center text-slate-500">Your inbox is clear.</div>@endforelse</div>
</div>
