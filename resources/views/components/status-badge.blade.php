@props(['status'])
@php
    $style = match ($status) {
        'completed', 'acknowledged' => 'bg-emerald-100 text-emerald-800',
        'pending_acknowledgment', 'pending' => 'bg-amber-100 text-amber-800',
        'revision_requested', 'declined' => 'bg-orange-100 text-orange-800',
        'cancelled' => 'bg-slate-200 text-slate-700',
        default => 'bg-blue-100 text-blue-800',
    };
@endphp
<span {{ $attributes->class("inline-flex rounded-full px-2.5 py-1 text-xs font-semibold $style") }}>{{ str($status)->replace('_', ' ')->title() }}</span>
