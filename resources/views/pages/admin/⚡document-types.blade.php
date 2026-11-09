<?php
use App\Actions\Documents\SaveDocumentType;
use App\Actions\Documents\ToggleDocumentType;
use App\Models\DocumentType;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Document Types')] class extends Component {
    public string $name = '';
    public string $code = '';
    public ?int $editingId = null;
    public function mount(): void { Gate::authorize('viewAny', DocumentType::class); }
    #[Computed] public function types() { return DocumentType::query()->withCount('documents')->orderBy('name')->get(); }
    public function edit(int $id): void { $type = DocumentType::findOrFail($id); Gate::authorize('update', $type); $this->editingId = $type->id; $this->name = $type->name; $this->code = $type->code; }
    public function save(SaveDocumentType $save): void { $type = $this->editingId ? DocumentType::findOrFail($this->editingId) : null; $save->handle(['name' => $this->name, 'code' => $this->code], $type); $this->reset('name', 'code', 'editingId'); unset($this->types); session()->flash('success', 'Document type saved.'); }
    public function toggle(int $id, ToggleDocumentType $toggle): void { $toggle->handle(DocumentType::findOrFail($id)); unset($this->types); }
};
?>
<div><x-page-heading title="Document Types" description="Administrator-managed classifications. SSO roles remain read-only." />
    <div class="grid gap-6 lg:grid-cols-[22rem_1fr]"><form wire:submit="save" class="space-y-4 rounded-2xl border border-slate-200 bg-white p-5"><h2 class="font-semibold">{{ $editingId ? 'Edit type' : 'Add type' }}</h2><label class="block"><span class="text-sm">Name</span><input wire:model="name" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"><x-form-error name="name" /></label><label class="block"><span class="text-sm">Code</span><input wire:model="code" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 uppercase"><x-form-error name="code" /></label><button class="w-full rounded-lg bg-fdcp-700 px-4 py-2 text-sm font-semibold text-white">Save type</button></form>
    <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white"><div class="divide-y divide-slate-100">@foreach($this->types as $type)<div class="flex items-center justify-between gap-4 p-4" wire:key="document-type-{{ $type->id }}"><div><p class="font-semibold">{{ $type->name }} <span class="text-slate-400">({{ $type->code }})</span></p><p class="text-xs text-slate-500">{{ $type->documents_count }} documents · {{ $type->is_active ? 'Active' : 'Inactive' }}</p></div><div class="flex gap-2"><button wire:click="edit({{ $type->id }})" class="rounded-lg border px-3 py-2 text-sm">Edit</button><button wire:click="toggle({{ $type->id }})" wire:confirm="Change this document type's availability?" class="rounded-lg border px-3 py-2 text-sm">{{ $type->is_active ? 'Deactivate' : 'Activate' }}</button></div></div>@endforeach</div></div></div>
</div>
