<?php
use App\Models\DocumentRoute;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Outgoing')] class extends Component {
    use WithPagination;
    #[Computed]
    public function routes()
    {
        return DocumentRoute::query()->where('from_user_id', auth()->id())
            ->with(['document:id,title,tracking_number,status', 'recipient:id,name', 'toDivision:id,code'])
            ->latest('forwarded_at')->paginate(20, pageName: 'outgoingPage');
    }
};
?>
<div><x-page-heading title="Outgoing" description="Documents you forwarded and their recipient status." />
    <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm"><div class="overflow-x-auto"><table class="min-w-full divide-y divide-slate-200 text-sm"><thead class="bg-slate-50 text-left text-xs uppercase text-slate-500"><tr><th class="px-5 py-3">Document</th><th class="px-5 py-3">Recipient</th><th class="px-5 py-3">Route</th><th class="px-5 py-3">Status</th><th class="px-5 py-3">Sent</th></tr></thead><tbody class="divide-y divide-slate-100">@forelse($this->routes as $route)<tr wire:key="outgoing-{{ $route->id }}"><td class="px-5 py-4"><a wire:navigate class="font-semibold text-fdcp-700" href="{{ route('documents.show', $route->document) }}">{{ $route->document->title }}</a><p class="text-xs text-slate-500">{{ $route->document->tracking_number }}</p></td><td class="px-5 py-4">{{ $route->recipient->name }}<p class="text-xs text-slate-500">{{ $route->toDivision->code }}</p></td><td class="px-5 py-4">{{ str($route->route_type)->replace('_',' ')->title() }}</td><td class="px-5 py-4"><x-status-badge :status="$route->status" /></td><td class="px-5 py-4 text-slate-500">{{ $route->forwarded_at->format('M j, Y g:i A') }}</td></tr>@empty<tr><td colspan="5" class="p-10 text-center text-slate-500">No outgoing routes yet.</td></tr>@endforelse</tbody></table></div>@if($this->routes->hasPages())<div class="border-t border-slate-200 p-4">{{ $this->routes->links() }}</div>@endif</div>
</div>
