@props(['title', 'description' => null])
<div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
    <div><h1 class="text-2xl font-bold tracking-tight text-slate-950">{{ $title }}</h1>@if($description)<p class="mt-1 text-sm text-slate-600">{{ $description }}</p>@endif</div>
    @isset($actions)<div class="flex gap-2">{{ $actions }}</div>@endisset
</div>
