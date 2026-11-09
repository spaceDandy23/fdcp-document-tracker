<?php
use App\Actions\Documents\CreateDocument;
use App\Actions\Documents\UploadDocumentAttachment;
use App\Actions\Documents\UploadDocumentVersion;
use App\Models\DocumentType;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\File;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Create Document')] class extends Component {
    use WithFileUploads;

    public string $title = '';
    public string $documentTypeId = '';
    public string $customDocumentType = '';
    public string $description = '';
    public $mainDocument;
    public array $attachmentFiles = [];

    #[Computed]
    public function documentTypes() { return DocumentType::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'code']); }

    public function save(CreateDocument $create, UploadDocumentVersion $upload, UploadDocumentAttachment $uploadAttachment): void
    {
        $attributes = Validator::make([
            'title' => $this->title,
            'document_type_id' => $this->documentTypeId,
            'custom_document_type' => trim($this->customDocumentType),
            'description' => $this->description,
            'mainDocument' => $this->mainDocument,
            'attachmentFiles' => $this->attachmentFiles,
        ], [
            'title' => ['required', 'string', 'max:255'],
            'document_type_id' => ['required'],
            'custom_document_type' => ['required_if:document_type_id,other', 'nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string'],
            'mainDocument' => ['nullable', File::types(['pdf', 'docx'])->max(config('documents.max_upload_kb'))],
            'attachmentFiles' => ['nullable', 'array'],
            'attachmentFiles.*' => [
                File::types(config('documents.attachment_types'))
                    ->max(config('documents.attachment_max_upload_kb')),
            ],
        ])->validate();

        $document = $create->handle($attributes);

        if ($this->mainDocument !== null) {
            try {
                $upload->handle($document, $this->mainDocument);
            } catch (\Throwable $exception) {
                Log::warning('Document draft main upload failed.', [
                    'document_id' => $document->id,
                    'category' => $exception::class,
                ]);
                session()->flash('error', 'Draft saved, but the main document could not be uploaded. You can retry from the document detail page.');
                $this->redirectRoute('documents.show', $document, navigate: true);

                return;
            }
        }

        try {
            foreach ($this->attachmentFiles as $attachmentFile) {
                $uploadAttachment->handle($document, $attachmentFile);
            }
        } catch (\Throwable $exception) {
            Log::warning('Document draft attachment upload failed.', [
                'document_id' => $document->id,
                'category' => $exception::class,
            ]);
            session()->flash('error', 'Draft saved, but one or more attachments could not be uploaded. You can retry from the document detail page.');
            $this->redirectRoute('documents.show', $document, navigate: true);

            return;
        }

        session()->flash('success', 'Draft created successfully.');
        $this->redirectRoute('documents.show', $document, navigate: true);
    }
};
?>
<div class="max-w-3xl">
    <x-page-heading title="Create Document" description="Start a draft. You can upload the main file before submission." />
    <form wire:submit="save" class="space-y-5 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        <label class="block"><span class="mb-1 block text-sm font-medium">Title</span><input wire:model="title" class="w-full rounded-lg border border-slate-300 px-3 py-2" maxlength="255" required><x-form-error name="title" /></label>
        <label class="block"><span class="mb-1 block text-sm font-medium">Document type</span><select wire:model.live="documentTypeId" class="w-full rounded-lg border border-slate-300 px-3 py-2" required><option value="">Select a type</option>@foreach($this->documentTypes as $type)<option value="{{ $type->id }}" wire:key="type-{{ $type->id }}">{{ $type->name }} ({{ $type->code }})</option>@endforeach<option value="other">Other / Specify</option></select><x-form-error name="document_type_id" /></label>
        @if($documentTypeId === 'other')<label class="block"><span class="mb-1 block text-sm font-medium">Custom document type</span><input wire:model="customDocumentType" maxlength="120" class="w-full rounded-lg border border-slate-300 px-3 py-2" required><x-form-error name="custom_document_type" /></label>@endif
        <label class="block"><span class="mb-1 block text-sm font-medium">Description <span class="text-slate-400">(optional)</span></span><textarea wire:model="description" rows="5" class="w-full rounded-lg border border-slate-300 px-3 py-2"></textarea><x-form-error name="description" /></label>
        <fieldset class="rounded-xl border border-dashed border-slate-300 p-4"><legend class="px-1 text-sm font-medium">Main document <span class="font-normal text-slate-400">(optional)</span></legend><p class="mt-1 text-sm text-slate-500">Upload a PDF or DOCX up to {{ number_format(config('documents.max_upload_kb') / 1024) }} MB.</p><input wire:model="mainDocument" type="file" accept=".pdf,.docx" class="mt-3 block w-full rounded-lg border border-slate-300 p-2 text-sm"><div wire:loading wire:target="mainDocument" class="mt-2 text-sm text-fdcp-700" role="status">Uploading selected file…</div>@if($mainDocument)<p class="mt-2 text-sm text-slate-700">Selected: {{ $mainDocument->getClientOriginalName() }}</p>@endif<x-form-error name="mainDocument" /></fieldset>
        <fieldset class="rounded-xl border border-dashed border-slate-300 p-4"><legend class="px-1 text-sm font-medium">Supporting attachments <span class="font-normal text-slate-400">(optional)</span></legend><p class="mt-1 text-sm text-slate-500">Add PDF or DOCX files up to {{ number_format(config('documents.attachment_max_upload_kb') / 1024) }} MB each.</p><input wire:model="attachmentFiles" type="file" accept=".pdf,.docx" multiple class="mt-3 block w-full rounded-lg border border-slate-300 p-2 text-sm"><div wire:loading wire:target="attachmentFiles" class="mt-2 text-sm text-fdcp-700" role="status">Uploading selected attachments…</div>@foreach($attachmentFiles as $attachmentFile)<p class="mt-2 text-sm text-slate-700" wire:key="new-attachment-{{ $loop->index }}">Selected: {{ $attachmentFile->getClientOriginalName() }}</p>@endforeach<x-form-error name="attachmentFiles" />@foreach($errors->get('attachmentFiles.*') as $messages)@foreach($messages as $message)<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@endforeach@endforeach</fieldset>
        <div class="flex justify-end gap-3"><a wire:navigate href="{{ route('documents.index') }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold">Cancel</a><button wire:loading.attr="disabled" class="rounded-lg bg-fdcp-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"><span wire:loading.remove>Save draft</span><span wire:loading>Saving…</span></button></div>
    </form>
</div>
