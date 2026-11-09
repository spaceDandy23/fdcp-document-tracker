<?php
use App\Actions\Documents\ListDocuments;
use App\Models\DocumentAction;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Dashboard')] class extends Component {
    #[Computed]
    public function statistics(): array
    {
        $documents = app(ListDocuments::class)->query();
        $userId = auth()->id();
        return [
            'Pending acknowledgment' => (clone $documents)->where('current_handler_id', $userId)->where('status', 'pending_acknowledgment')->count(),
            'Currently handling' => (clone $documents)->where('current_handler_id', $userId)->whereIn('status', ['in_progress', 'revision_requested'])->count(),
            'Created documents' => (clone $documents)->where('created_by', $userId)->count(),
            'Completed documents' => (clone $documents)->where('status', 'completed')->count(),
        ];
    }

    #[Computed]
    public function recentActivity()
    {
        return DocumentAction::query()->whereIn('document_id', app(ListDocuments::class)->query()->select('documents.id'))
            ->with(['document:id,title,tracking_number', 'performer:id,name'])->latest('id')->limit(8)->get();
    }
};
?>
<div>
    <x-page-heading title="Dashboard" description="Your authorized document workload and recent activity." />
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($this->statistics as $label => $value)<article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm" wire:key="stat-{{ $label }}"><p class="text-sm text-slate-500">{{ $label }}</p><p class="mt-3 text-3xl font-bold">{{ $value }}</p></article>@endforeach
    </div>
    <section class="mt-8 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-200 px-5 py-4"><h2 class="font-semibold">Recent activity</h2></div>
        <div class="divide-y divide-slate-100">@forelse ($this->recentActivity as $activity)<a wire:navigate href="{{ route('documents.show', $activity->document) }}" class="flex items-start justify-between gap-4 px-5 py-4 hover:bg-slate-50" wire:key="activity-{{ $activity->id }}"><div><p class="font-medium">{{ $activity->document->title }}</p><p class="text-sm text-slate-500">{{ str($activity->action)->replace('_', ' ')->title() }} by {{ $activity->performer?->name ?? 'System' }}</p></div><time class="shrink-0 text-xs text-slate-500">{{ $activity->created_at->diffForHumans() }}</time></a>@empty<p class="p-8 text-center text-sm text-slate-500">No document activity yet.</p>@endforelse</div>
    </section>
</div>
