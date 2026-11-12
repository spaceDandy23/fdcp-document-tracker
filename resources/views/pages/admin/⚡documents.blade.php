<?php
use App\Models\Document;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Administration')] class extends Component {
    use WithPagination;
    #[Url] public string $search = '';
    public function mount(): void { abort_unless(auth()->user()->is_admin, 403); }
    public function hydrate(): void { abort_unless(auth()->user()->is_admin, 403); }
    #[Computed]
    public function documents()
    {
        abort_unless(auth()->user()->is_admin, 403);

        return Document::query()->with(['documentType:id,name', 'originDivision:id,code', 'creator:id,name', 'currentHandler:id,name'])
            ->when($this->search !== '', fn ($query) => $query->where(fn ($query) => $query->where('title', 'like', '%'.$this->search.'%')->orWhere('tracking_number', 'like', '%'.$this->search.'%')))
            ->latest()->paginate(20);
    }
};
?>
<div><x-page-heading title="All Documents" description="Administrator oversight across the organization." /><label class="mb-5 block"><span class="sr-only">Search all documents</span><input wire:model.live.debounce.300ms="search" type="search" placeholder="Search all documents" class="w-full max-w-xl rounded-lg border border-slate-300 px-3 py-2"></label>
    <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white"><div class="divide-y divide-slate-100">@forelse($this->documents as $document)<a wire:navigate href="{{ route('documents.show', $document) }}" class="flex flex-col gap-2 p-5 hover:bg-slate-50 sm:flex-row sm:items-center sm:justify-between" wire:key="admin-document-{{ $document->id }}"><div><p class="font-semibold text-fdcp-700">{{ $document->title }}</p><p class="text-sm text-slate-500">{{ $document->originDivision->code }} Â· {{ $document->creator->name }} Â· {{ $document->tracking_number ?? 'Draft' }}</p></div><x-status-badge :status="$document->status" /></a>@empty<p class="p-10 text-center text-slate-500">No documents found.</p>@endforelse</div><div class="border-t p-4">{{ $this->documents->links() }}</div></div>
</div>

