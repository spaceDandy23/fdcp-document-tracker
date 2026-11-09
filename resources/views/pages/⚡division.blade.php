<?php
use App\Actions\Documents\ListDocuments;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Division Overview')] class extends Component {
    use WithPagination;
    public function mount(): void { abort_unless(auth()->user()->is_division_head || auth()->user()->is_admin, 403); }
    #[Computed]
    public function documents()
    {
        $divisionId = auth()->user()->division_id;
        return app(ListDocuments::class)->query()->where(fn ($query) => $query->where('origin_division_id', $divisionId)->orWhereHas('routes', fn ($query) => $query->where('from_division_id', $divisionId)->orWhere('to_division_id', $divisionId)))
            ->with(['documentType:id,name', 'creator:id,name', 'currentHandler:id,name'])->latest()->paginate(15);
    }
};
?>
<div><x-page-heading title="Division Overview" :description="'Documents originating from or routed through '.(auth()->user()->division?->name ?? 'your division').'.'" />
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">@forelse($this->documents as $document)<a wire:navigate href="{{ route('documents.show', $document) }}" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm hover:border-fdcp-600" wire:key="division-document-{{ $document->id }}"><div class="flex items-start justify-between gap-3"><h2 class="font-semibold">{{ $document->title }}</h2><x-status-badge :status="$document->status" /></div><p class="mt-3 text-sm text-slate-500">{{ $document->creator->name }} · {{ $document->documentType->name }}</p></a>@empty<div class="col-span-full rounded-2xl border border-dashed p-12 text-center text-slate-500">No division documents found.</div>@endforelse</div><div class="mt-5">{{ $this->documents->links() }}</div>
</div>
