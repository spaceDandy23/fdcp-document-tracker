<?php
use App\Actions\Documents\ListDocuments;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('My Documents')] class extends Component {
    use WithPagination;
    #[Url] public string $search = '';
    #[Url] public string $status = '';
    #[Url] public string $sort = 'updated_at';
    #[Url] public string $direction = 'desc';

    public function updatedSearch(): void { $this->resetPage(); }
    public function updatedStatus(): void { $this->resetPage(); }
    public function sortBy(string $column): void
    {
        abort_unless(in_array($column, ['title', 'status', 'updated_at'], true), 422);
        $this->direction = $this->sort === $column && $this->direction === 'asc' ? 'desc' : 'asc';
        $this->sort = $column;
    }

    #[Computed]
    public function documents()
    {
        return app(ListDocuments::class)->query()
            ->with(['documentType:id,name', 'currentHandler:id,name'])
            ->when($this->search !== '', fn ($query) => $query->where(fn ($query) => $query->where('title', 'like', '%'.$this->search.'%')->orWhere('tracking_number', 'like', '%'.$this->search.'%')))
            ->when($this->status !== '', fn ($query) => $query->where('status', $this->status))
            ->orderBy($this->sort, $this->direction === 'asc' ? 'asc' : 'desc')->paginate(12);
    }
};
?>
<div>
    <x-page-heading title="My Documents" description="Search every document you are authorized to view."><x-slot:actions><a wire:navigate href="{{ route('documents.create') }}" class="rounded-lg bg-fdcp-700 px-4 py-2 text-sm font-semibold text-white hover:bg-fdcp-800">Create document</a></x-slot:actions></x-page-heading>
    <div class="mb-5 grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 sm:grid-cols-[1fr_14rem]">
        <label><span class="sr-only">Search documents</span><input wire:model.live.debounce.300ms="search" type="search" placeholder="Search title or tracking number" class="w-full rounded-lg border border-slate-300 px-3 py-2"></label>
        <label><span class="sr-only">Filter by status</span><select wire:model.live="status" class="w-full rounded-lg border border-slate-300 px-3 py-2"><option value="">All statuses</option>@foreach (['draft','pending_acknowledgment','in_progress','revision_requested','completed','cancelled'] as $value)<option value="{{ $value }}" wire:key="status-{{ $value }}">{{ str($value)->replace('_',' ')->title() }}</option>@endforeach</select></label>
    </div>
    <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="overflow-x-auto"><table class="min-w-full divide-y divide-slate-200 text-sm"><thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-5 py-3"><button wire:click="sortBy('title')">Document</button></th><th class="px-5 py-3">Type</th><th class="px-5 py-3"><button wire:click="sortBy('status')">Status</button></th><th class="px-5 py-3">Handler</th><th class="px-5 py-3"><button wire:click="sortBy('updated_at')">Updated</button></th></tr></thead><tbody class="divide-y divide-slate-100">
        @forelse($this->documents as $document)<tr class="hover:bg-slate-50" wire:key="document-{{ $document->id }}"><td><a wire:navigate class="block px-5 py-4 font-semibold text-fdcp-700 hover:underline" href="{{ route('documents.show', $document) }}">{{ $document->title }}<span class="mt-1 block text-xs font-normal text-slate-500">{{ $document->tracking_number ?? 'Draft' }}</span></a></td><td><a wire:navigate class="block px-5 py-4 text-slate-900" href="{{ route('documents.show', $document) }}">{{ $document->document_type_label }}</a></td><td><a wire:navigate class="block px-5 py-4" href="{{ route('documents.show', $document) }}"><x-status-badge :status="$document->status" /></a></td><td><a wire:navigate class="block px-5 py-4 text-slate-900" href="{{ route('documents.show', $document) }}">{{ $document->currentHandler?->name ?? '—' }}</a></td><td><a wire:navigate class="block px-5 py-4 text-slate-500" href="{{ route('documents.show', $document) }}">{{ $document->updated_at->diffForHumans() }}</a></td></tr>@empty<tr><td colspan="5" class="p-10 text-center text-slate-500">No documents match your search.</td></tr>@endforelse</tbody></table></div>
        <div class="border-t border-slate-200 p-4">{{ $this->documents->links() }}</div>
    </div>
</div>
