<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">

        <title>{{ isset($title) ? $title.' · ' : '' }}FDCP Document Tracker</title>

        @vite(['resources/css/app.css', 'resources/js/app.js'])

        @livewireStyles
    </head>
    <body x-data="{ sidebarOpen: false }" class="overflow-x-hidden">
        <div class="min-h-dvh overflow-x-hidden lg:flex lg:h-dvh lg:overflow-hidden">
            <div x-show="sidebarOpen" x-cloak x-transition.opacity class="fixed inset-0 z-30 bg-slate-950/40 lg:hidden" @click="sidebarOpen = false"></div>
            <aside :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'" class="fixed inset-y-0 left-0 z-40 flex h-dvh w-72 flex-col bg-slate-950 text-white transition lg:static lg:translate-x-0">
                <div class="flex h-20 items-center gap-3 border-b border-white/10 px-6">
                    <div class="grid size-10 place-items-center rounded-xl bg-fdcp-600 font-bold">FD</div>
                    <div><p class="font-semibold">FDCP</p><p class="text-xs text-slate-400">Document Tracking System</p></div>
                </div>
                <nav aria-label="Primary" class="min-h-0 flex-1 space-y-1 overflow-y-auto p-4 text-sm">
                    @foreach ([['home', 'Dashboard'], ['inbox', 'Inbox'], ['outgoing', 'Outgoing'], ['documents.index', 'My Documents'], ['documents.create', 'Create Document']] as [$routeName, $label])
                        <a wire:navigate href="{{ route($routeName) }}" @class(['flex rounded-lg px-3 py-2.5 font-medium transition', 'bg-fdcp-700 text-white' => request()->routeIs($routeName), 'text-slate-300 hover:bg-white/10 hover:text-white' => ! request()->routeIs($routeName)]) wire:key="nav-{{ $routeName }}">{{ $label }}</a>
                    @endforeach
                    @if (auth()->user()->is_division_head || auth()->user()->is_admin)
                        <a wire:navigate href="{{ route('division') }}" @class(['flex rounded-lg px-3 py-2.5 font-medium', 'bg-fdcp-700' => request()->routeIs('division'), 'text-slate-300 hover:bg-white/10' => ! request()->routeIs('division')])>Division Overview</a>
                        <a wire:navigate href="{{ route('reports') }}" @class(['flex rounded-lg px-3 py-2.5 font-medium', 'bg-fdcp-700' => request()->routeIs('reports'), 'text-slate-300 hover:bg-white/10' => ! request()->routeIs('reports')])>Reporting</a>
                    @endif
                    @if (auth()->user()->is_admin)
                        <p class="px-3 pt-5 text-xs font-semibold uppercase tracking-wider text-slate-500">Administration</p>
                        <a wire:navigate href="{{ route('admin.documents') }}" class="flex rounded-lg px-3 py-2.5 text-slate-300 hover:bg-white/10">All Documents</a>
                        <a wire:navigate href="{{ route('admin.document-types') }}" class="flex rounded-lg px-3 py-2.5 text-slate-300 hover:bg-white/10">Document Types</a>
                    @endif
                </nav>
                <div class="border-t border-white/10 p-4">
                    <p class="truncate font-medium">{{ auth()->user()->name }}</p>
                    <p class="truncate text-xs text-slate-400">{{ auth()->user()->division?->code ?? 'No division' }} · {{ auth()->user()->is_admin ? 'Administrator' : (auth()->user()->is_division_head ? 'Division Head' : 'Staff') }}</p>
                </div>
            </aside>
            <div class="min-w-0 flex-1 lg:h-dvh lg:overflow-y-auto">
                <header class="sticky top-0 z-20 flex h-16 items-center justify-between border-b border-slate-200 bg-white/95 px-4 backdrop-blur sm:px-6">
                    <button type="button" class="rounded-lg p-2 text-slate-600 lg:hidden" @click="sidebarOpen = true" aria-label="Open navigation">☰</button>
                    <p class="hidden text-sm text-slate-500 sm:block">FDCP Internal Document Services</p>
                    <div class="ml-auto flex items-center gap-3"><livewire:notification-bell /><form method="POST" action="{{ route('logout') }}">@csrf<button class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium hover:bg-slate-50">Sign out</button></form></div>
                </header>
                <main class="mx-auto max-w-7xl p-4 sm:p-6 lg:p-8">
                    <nav aria-label="Breadcrumb" class="mb-4 flex items-center gap-2 text-xs text-slate-500">
                        <a wire:navigate href="{{ route('home') }}" class="hover:text-fdcp-700">Home</a>
                        @unless(request()->routeIs('home'))<span aria-hidden="true">/</span><span aria-current="page">{{ str(request()->route()?->getName() ?? 'Page')->replace(['.', '-'], ' ')->title() }}</span>@endunless
                    </nav>
                    @if (session('success'))<div role="status" class="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800">{{ session('success') }}</div>@endif
                    @if (session('error'))<div role="alert" class="mb-6 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">{{ session('error') }}</div>@endif
                    {{ $slot }}
                </main>
            </div>
        </div>

        @livewireScripts
    </body>
</html>
