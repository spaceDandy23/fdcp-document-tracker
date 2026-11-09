<?php
use App\Actions\Documents\AcknowledgeDocumentRoute;
use App\Actions\Documents\CancelDocument;
use App\Actions\Documents\CompleteDocument;
use App\Actions\Documents\DeclineDocumentRoute;
use App\Actions\Documents\ForwardDocument;
use App\Actions\Documents\ReturnDocumentForRevision;
use App\Actions\Documents\SubmitDocument;
use App\Actions\Documents\UpdateDocumentDraft;
use App\Actions\Documents\UploadDocumentVersion;
use App\Actions\Documents\UploadDocumentAttachment;
use App\Models\Document;
use App\Models\DocumentRoute;
use App\Models\DocumentType;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\File;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Document Details')] class extends Component {
    use WithFileUploads;

    public Document $document;
    public string $title = '';
    public string $description = '';
    public string $documentTypeId = '';
    public string $customDocumentType = '';
    public $file;
    public array $attachmentFiles = [];
    public string $changeSummary = '';
    public string $recipientId = '';
    public string $remarks = '';
    public string $declineReason = '';
    public string $revisionReason = '';
    public string $revisionRecipientId = '';

    public function mount(Document $document): void
    {
        Gate::authorize('view', $document);
        $this->document = $document;
        $this->fillDraftFields();
    }

    private function fillDraftFields(): void
    {
        $this->title = $this->document->title;
        $this->description = $this->document->description ?? '';
        $this->documentTypeId = $this->document->custom_document_type === null
            ? (string) $this->document->document_type_id
            : 'other';
        $this->customDocumentType = $this->document->custom_document_type ?? '';
    }

    public function updatedDocumentTypeId(string $documentTypeId): void
    {
        if ($documentTypeId !== 'other') {
            $this->customDocumentType = '';
        }
    }

    private function refreshDocument(): void
    {
        $this->document = $this->document->fresh();
        unset($this->attachments, $this->pendingRoute, $this->versions, $this->timeline, $this->recipients, $this->previousHandlers);
    }

    #[Computed]
    public function documentTypes() { return DocumentType::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']); }
    #[Computed]
    public function recipients() { return User::query()->where('is_active', true)->whereNotNull('division_id')->whereKeyNot(auth()->id())->with('division:id,code')->orderBy('name')->get(['id', 'name', 'division_id']); }
    #[Computed]
    public function previousHandlers()
    {
        $ids = $this->document->routes()->pluck('from_user_id')->push($this->document->created_by)->unique()->reject(fn ($id) => $id === auth()->id());
        return User::query()->whereKey($ids)->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }
    #[Computed]
    public function pendingRoute() { return $this->document->routes()->where('to_user_id', auth()->id())->where('status', 'pending')->latest('id')->first(); }
    #[Computed]
    public function versions() { return $this->document->versions()->with('uploader:id,name')->latest('version_number')->get(); }
    #[Computed]
    public function attachments() { return $this->document->attachments()->with('uploader:id,name')->latest('id')->get(); }
    #[Computed]
    public function timeline() { return $this->document->actions()->with(['performer:id,name', 'documentRoute.sender:id,name', 'documentRoute.recipient:id,name'])->latest('id')->get(); }

    public function saveDraft(UpdateDocumentDraft $update): void
    {
        $update->handle($this->document, ['title' => $this->title, 'description' => $this->description, 'document_type_id' => $this->documentTypeId ?: 'other', 'custom_document_type' => $this->customDocumentType]);
        $this->refreshDocument(); session()->flash('success', 'Draft updated.');
    }

    public function uploadVersion(UploadDocumentVersion $upload): void
    {
        $upload->handle($this->document, $this->file, $this->changeSummary ?: null);
        $this->reset('file', 'changeSummary'); $this->refreshDocument(); session()->flash('success', 'Document version uploaded.');
    }

    public function uploadAttachments(UploadDocumentAttachment $upload): void
    {
        Validator::make(['attachmentFiles' => $this->attachmentFiles], [
            'attachmentFiles' => ['required', 'array', 'min:1'],
            'attachmentFiles.*' => [
                File::types(config('documents.attachment_types'))
                    ->max(config('documents.attachment_max_upload_kb')),
            ],
        ])->validate();

        foreach ($this->attachmentFiles as $attachmentFile) {
            $upload->handle($this->document, $attachmentFile);
        }

        $this->reset('attachmentFiles');
        $this->refreshDocument();
        session()->flash('success', 'Supporting attachments uploaded.');
    }

    public function submit(SubmitDocument $submit): void
    {
        $submit->handle($this->document, ['recipient_id' => $this->recipientId]);
        $this->reset('recipientId'); $this->refreshDocument(); session()->flash('success', 'Document submitted for acknowledgment.');
    }

    public function forward(ForwardDocument $forward): void
    {
        $forward->handle($this->document, ['recipient_id' => $this->recipientId, 'remarks' => $this->remarks]);
        $this->reset('recipientId', 'remarks'); $this->refreshDocument(); session()->flash('success', 'Document forwarded.');
    }

    public function acknowledge(AcknowledgeDocumentRoute $acknowledge): void
    {
        abort_if($this->pendingRoute === null, 404); $acknowledge->handle($this->pendingRoute); $this->refreshDocument(); session()->flash('success', 'Document acknowledged.');
    }

    public function decline(DeclineDocumentRoute $decline): void
    {
        abort_if($this->pendingRoute === null, 404); $decline->handle($this->pendingRoute, ['reason' => $this->declineReason]); $this->reset('declineReason'); $this->refreshDocument(); session()->flash('success', 'Document declined.');
    }

    public function returnForRevision(ReturnDocumentForRevision $return): void
    {
        $return->handle($this->document, ['reason' => $this->revisionReason, 'recipient_id' => $this->revisionRecipientId ?: null]);
        $this->reset('revisionReason', 'revisionRecipientId'); $this->refreshDocument(); session()->flash('success', 'Document returned for revision.');
    }

    public function complete(CompleteDocument $complete): void { $complete->handle($this->document); $this->refreshDocument(); session()->flash('success', 'Document completed.'); }
    public function cancel(CancelDocument $cancel): void { $cancel->handle($this->document); $this->refreshDocument(); session()->flash('success', 'Draft cancelled.'); }
};
?>
<div>
    <x-page-heading :title="$document->title" :description="$document->tracking_number ?? 'Unsubmitted draft'"><x-slot:actions><x-status-badge :status="$document->status" /></x-slot:actions></x-page-heading>
    <div class="grid gap-6 xl:grid-cols-[minmax(0,2fr)_minmax(20rem,1fr)]">
        <div class="space-y-6">
            <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <h2 class="mb-4 font-semibold">Document information</h2>
                <dl class="grid gap-4 text-sm sm:grid-cols-2"><div><dt class="text-slate-500">Classification</dt><dd class="font-medium">{{ $document->document_type_label }}</dd></div><div><dt class="text-slate-500">Creator</dt><dd class="font-medium">{{ $document->creator->name }}</dd></div><div><dt class="text-slate-500">Originating division</dt><dd class="font-medium">{{ $document->originDivision->name }}</dd></div><div><dt class="text-slate-500">Current handler</dt><dd class="font-medium">{{ $document->currentHandler?->name ?? 'None' }}</dd></div><div><dt class="text-slate-500">Submitted</dt><dd>{{ $document->submitted_at?->format('M j, Y g:i A') ?? 'Not submitted' }}</dd></div><div><dt class="text-slate-500">Completed</dt><dd>{{ $document->completed_at?->format('M j, Y g:i A') ?? '—' }}</dd></div></dl>
                @if($document->description)<p class="mt-5 whitespace-pre-line border-t border-slate-100 pt-5 text-sm text-slate-700">{{ $document->description }}</p>@endif
            </section>

            @can('updateDraft', $document)<section class="rounded-2xl border border-slate-200 bg-white p-6"><h2 class="mb-4 font-semibold">Edit draft</h2><form wire:submit="saveDraft" class="space-y-4"><input wire:model="title" aria-label="Title" class="w-full rounded-lg border border-slate-300 px-3 py-2"><x-form-error name="title" /><select wire:model.live="documentTypeId" aria-label="Document type" class="w-full rounded-lg border border-slate-300 px-3 py-2"><option value="">Select a type</option>@foreach($this->documentTypes as $type)<option value="{{ $type->id }}" wire:key="edit-type-{{ $type->id }}">{{ $type->name }}</option>@endforeach<option value="other">Other / Specify</option></select><x-form-error name="document_type_id" />@if($documentTypeId === 'other')<input wire:model="customDocumentType" aria-label="Custom document type" maxlength="120" class="w-full rounded-lg border border-slate-300 px-3 py-2" required><x-form-error name="custom_document_type" />@endif<textarea wire:model="description" aria-label="Description" rows="4" class="w-full rounded-lg border border-slate-300 px-3 py-2"></textarea><x-form-error name="description" /><button class="rounded-lg bg-fdcp-700 px-4 py-2 text-sm font-semibold text-white">Save changes</button></form></section>@endcan

            @can('uploadVersion', $document)<section class="rounded-2xl border border-slate-200 bg-white p-6"><h2 class="font-semibold">Upload {{ $document->status === 'revision_requested' ? 'revision' : 'main document' }}</h2><p class="mt-1 text-sm text-slate-500">PDF or DOCX, up to {{ number_format(config('documents.max_upload_kb') / 1024) }} MB.</p><form wire:submit="uploadVersion" class="mt-4 space-y-4"><input wire:model="file" type="file" accept=".pdf,.docx" class="block w-full rounded-lg border border-slate-300 p-2 text-sm"><div wire:loading wire:target="file" class="text-sm text-fdcp-700">Uploading…</div><x-form-error name="file" />@if($document->status === 'revision_requested')<textarea wire:model="changeSummary" required placeholder="Required change summary" class="w-full rounded-lg border border-slate-300 px-3 py-2"></textarea><x-form-error name="change_summary" />@endif<button wire:loading.attr="disabled" class="rounded-lg bg-fdcp-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Save version</button></form></section>@endcan

            <section class="rounded-2xl border border-slate-200 bg-white p-6"><h2 class="mb-4 font-semibold">Versions</h2><div class="divide-y divide-slate-100">@forelse($this->versions as $version)<div class="flex items-center justify-between gap-4 py-3" wire:key="version-{{ $version->id }}"><div><p class="font-medium">Version {{ $version->version_number }} · {{ $version->original_filename }}</p><p class="text-xs text-slate-500">{{ $version->uploader->name }} · {{ number_format($version->file_size / 1024) }} KB · {{ $version->created_at->format('M j, Y') }}</p>@if($version->change_summary)<p class="mt-1 text-sm">{{ $version->change_summary }}</p>@endif</div><a href="{{ route('documents.versions.download', [$document, $version]) }}" class="shrink-0 rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold">Download</a></div>@empty<p class="py-6 text-center text-sm text-slate-500">No main document uploaded.</p>@endforelse</div></section>

            <section class="rounded-2xl border border-slate-200 bg-white p-6"><h2 class="mb-4 font-semibold">Supporting attachments</h2>@can('uploadAttachment', $document)<form wire:submit="uploadAttachments" class="mb-5 rounded-xl border border-dashed border-slate-300 p-4"><p class="text-sm text-slate-500">PDF or DOCX, up to {{ number_format(config('documents.attachment_max_upload_kb') / 1024) }} MB each.</p><div x-data="{ uploading: false, progress: 0 }" x-on:livewire-upload-start="uploading = true" x-on:livewire-upload-finish="uploading = false" x-on:livewire-upload-error="uploading = false" x-on:livewire-upload-progress="progress = $event.detail.progress"><input wire:model="attachmentFiles" type="file" accept=".pdf,.docx" multiple class="mt-3 block w-full rounded-lg border border-slate-300 p-2 text-sm"><div x-show="uploading" x-cloak class="mt-2 text-sm text-fdcp-700" role="status">Uploading selected attachments: <span x-text="progress + '%'"></span></div></div>@foreach($attachmentFiles as $attachmentFile)<p class="mt-2 text-sm text-slate-700" wire:key="attachment-file-{{ $loop->index }}">Selected: {{ $attachmentFile->getClientOriginalName() }}</p>@endforeach<x-form-error name="attachmentFiles" />@foreach($errors->get('attachmentFiles.*') as $messages)@foreach($messages as $message)<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@endforeach@endforeach<button wire:loading.attr="disabled" wire:target="uploadAttachments,attachmentFiles" class="mt-3 rounded-lg bg-fdcp-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Upload attachments</button></form>@endcan<div class="divide-y divide-slate-100">@forelse($this->attachments as $attachment)<div class="flex items-center justify-between gap-4 py-3" wire:key="attachment-{{ $attachment->id }}"><div class="min-w-0"><p class="truncate font-medium">{{ $attachment->original_filename }}</p><p class="text-xs text-slate-500">{{ $attachment->uploader->name }} · {{ number_format($attachment->file_size / 1024) }} KB · {{ $attachment->created_at->format('M j, Y') }}</p></div><a href="{{ route('documents.attachments.download', [$document, $attachment]) }}" class="shrink-0 rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold">Download</a></div>@empty<p class="py-4 text-sm text-slate-500">No supporting attachments uploaded.</p>@endforelse</div></section>
        </div>

        <aside class="space-y-6">
            @if($this->pendingRoute && Gate::allows('acknowledge', [$document, $this->pendingRoute]))<section class="rounded-2xl border border-amber-200 bg-amber-50 p-5"><h2 class="font-semibold">Acknowledgment required</h2><div class="mt-4 space-y-3"><button wire:click="acknowledge" wire:confirm="Acknowledge this document?" class="w-full rounded-lg bg-fdcp-700 px-4 py-2 text-sm font-semibold text-white">Acknowledge</button><textarea wire:model="declineReason" placeholder="Reason required to decline" class="w-full rounded-lg border border-amber-300 px-3 py-2 text-sm"></textarea><x-form-error name="reason" /><button wire:click="decline" wire:confirm="Decline and return this document?" class="w-full rounded-lg border border-red-300 px-4 py-2 text-sm font-semibold text-red-700">Decline</button></div></section>@endif

            @can('submit', $document)<section class="rounded-2xl border border-slate-200 bg-white p-5"><h2 class="font-semibold">{{ $document->status === 'revision_requested' ? 'Resubmit revision' : 'Submit document' }}</h2><select wire:model="recipientId" class="mt-4 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"><option value="">Select one recipient</option>@foreach($this->recipients as $recipient)<option value="{{ $recipient->id }}" wire:key="submit-recipient-{{ $recipient->id }}">{{ $recipient->name }} ({{ $recipient->division->code }})</option>@endforeach</select><x-form-error name="recipient_id" /><x-form-error name="document" /><button wire:click="submit" wire:confirm="Submit this document?" class="mt-3 w-full rounded-lg bg-fdcp-700 px-4 py-2 text-sm font-semibold text-white">Submit</button><p class="mt-2 text-xs text-slate-500">Only synchronized employees who have logged in are available.</p></section>@endcan

            @can('forward', $document)<section class="rounded-2xl border border-slate-200 bg-white p-5"><h2 class="font-semibold">Forward document</h2><select wire:model="recipientId" class="mt-4 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"><option value="">Select recipient</option>@foreach($this->recipients as $recipient)<option value="{{ $recipient->id }}" wire:key="forward-recipient-{{ $recipient->id }}">{{ $recipient->name }} ({{ $recipient->division->code }})</option>@endforeach</select><textarea wire:model="remarks" placeholder="Remarks (optional)" class="mt-3 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></textarea><button wire:click="forward" wire:confirm="Forward this document?" class="mt-3 w-full rounded-lg bg-fdcp-700 px-4 py-2 text-sm font-semibold text-white">Forward</button></section>@endcan

            @can('returnForRevision', $document)<section class="rounded-2xl border border-slate-200 bg-white p-5"><h2 class="font-semibold">Return for revision</h2><textarea wire:model="revisionReason" required placeholder="Revision reason" class="mt-4 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></textarea><select wire:model="revisionRecipientId" class="mt-3 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"><option value="">Immediate sender (default)</option>@foreach($this->previousHandlers as $handler)<option value="{{ $handler->id }}" wire:key="previous-handler-{{ $handler->id }}">{{ $handler->name }}</option>@endforeach</select><button wire:click="returnForRevision" wire:confirm="Return this document for revision?" class="mt-3 w-full rounded-lg border border-orange-300 px-4 py-2 text-sm font-semibold text-orange-800">Return for revision</button></section>@endcan

            <section class="rounded-2xl border border-slate-200 bg-white p-5"><h2 class="font-semibold">Final actions</h2><div class="mt-4 space-y-3">@can('complete', $document)<button wire:click="complete" wire:confirm="Complete this document? This cannot be undone." class="w-full rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white">Mark completed</button>@endcan @can('cancel', $document)<button wire:click="cancel" wire:confirm="Cancel this draft?" class="w-full rounded-lg border border-red-300 px-4 py-2 text-sm font-semibold text-red-700">Cancel draft</button>@endcan</div></section>

            <section class="rounded-2xl border border-slate-200 bg-white p-5"><h2 class="mb-4 font-semibold">Audit timeline</h2><ol class="space-y-4">@foreach($this->timeline as $action)<li class="relative border-l-2 border-slate-200 pl-4" wire:key="action-{{ $action->id }}"><p class="text-sm font-medium">{{ str($action->action)->replace('_',' ')->title() }}</p><p class="text-xs text-slate-500">{{ $action->performer?->name ?? 'System' }} · {{ $action->created_at->format('M j, Y g:i A') }}</p>@if($action->remarks)<p class="mt-1 text-sm text-slate-700">{{ $action->remarks }}</p>@endif</li>@endforeach</ol></section>
        </aside>
    </div>
</div>
