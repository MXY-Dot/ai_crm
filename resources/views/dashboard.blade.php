<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Gravity AI CRM</title>
    @unless (app()->environment('testing'))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endunless
</head>
<body class="min-h-screen bg-zinc-950 text-zinc-100 antialiased">
    <main class="mx-auto flex min-h-screen w-full max-w-7xl flex-col gap-6 px-4 py-5 sm:px-6 lg:px-8">
        <header class="flex flex-col gap-4 border-b border-white/10 pb-5 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <p class="text-sm font-medium text-emerald-300">Gravity AI CRM</p>
                <h1 class="mt-1 text-2xl font-semibold tracking-normal text-white sm:text-3xl">Omnichannel SaaS dashboard</h1>
                <p class="mt-2 max-w-3xl text-sm leading-6 text-zinc-400">
                    Tenant CRM foundation with companies, customers, leads, tasks, roles and tenant isolation ready for Chatwoot and Dify.
                </p>
            </div>
            <div class="rounded-md border border-white/10 bg-white/5 px-4 py-3 text-sm text-zinc-300">
                <span class="block text-xs uppercase tracking-wide text-zinc-500">API tenant header</span>
                <code class="mt-1 block text-emerald-200">X-Tenant-Id: {{ $tenant?->slug ?? 'demo' }}</code>
            </div>
        </header>

        @if (! $tenant)
            <section class="rounded-md border border-amber-300/30 bg-amber-300/10 p-5 text-amber-100">
                <h2 class="text-base font-semibold">No demo data found</h2>
                <p class="mt-2 text-sm text-amber-100/80">Run <code>php artisan db:seed</code> to create the demo tenant, company, leads and tasks.</p>
            </section>
        @else
            <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($stats as $label => $value)
                    <article class="rounded-md border border-white/10 bg-zinc-900 p-4">
                        <p class="text-sm text-zinc-400">{{ $label }}</p>
                        <p class="mt-3 text-3xl font-semibold text-white">{{ $value }}</p>
                    </article>
                @endforeach
            </section>

            <section class="grid gap-6 lg:grid-cols-[1.1fr_0.9fr]">
                <div class="rounded-md border border-white/10 bg-zinc-900">
                    <div class="flex items-center justify-between border-b border-white/10 px-4 py-3">
                        <h2 class="text-base font-semibold text-white">Lead pipeline</h2>
                        <span class="text-sm text-zinc-400">{{ $company?->name ?? 'No company' }}</span>
                    </div>
                    <div class="divide-y divide-white/10">
                        @foreach ($leads as $lead)
                            <div class="grid gap-3 px-4 py-4 sm:grid-cols-[1fr_auto] sm:items-center">
                                <div>
                                    <p class="font-medium text-white">{{ $lead->title }}</p>
                                    <p class="mt-1 text-sm text-zinc-400">{{ $lead->source ?? 'manual' }} · AI score {{ $lead->score }}</p>
                                </div>
                                <span class="w-fit rounded-sm border border-emerald-300/30 bg-emerald-300/10 px-2 py-1 text-xs font-medium text-emerald-200">{{ $lead->status }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="rounded-md border border-white/10 bg-zinc-900">
                    <div class="border-b border-white/10 px-4 py-3">
                        <h2 class="text-base font-semibold text-white">Operator tasks</h2>
                    </div>
                    <div class="divide-y divide-white/10">
                        @foreach ($tasks as $task)
                            <div class="px-4 py-4">
                                <div class="flex items-start justify-between gap-3">
                                    <p class="font-medium text-white">{{ $task->title }}</p>
                                    <span class="rounded-sm border border-white/10 px-2 py-1 text-xs text-zinc-300">{{ $task->priority }}</span>
                                </div>
                                <p class="mt-2 text-sm text-zinc-400">Status: {{ str_replace('_', ' ', $task->status) }}</p>
                            </div>
                        @endforeach
                    </div>
                </div>
            </section>
        @endif
    </main>
</body>
</html>