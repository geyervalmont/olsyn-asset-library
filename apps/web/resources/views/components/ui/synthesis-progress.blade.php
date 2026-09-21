@props(['run', 'currentRevision'])
@php
    $stages = $run->stages();
    $stageIndex = array_search($run->stage, array_keys($stages), true);
    $progress = $run->manifest['progress'] ?? [];
    $elapsed = (int) $run->created_at->diffInSeconds($run->status === 'succeeded' ? ($run->heartbeat_at ?? $run->updated_at) : ($run->terminal() ? $run->updated_at : now()));
    $hints = [
        'queued' => 'Your draft is saved. Waiting for an available generation slot.',
        'waiting_for_compute' => 'Starting a GPU worker. A cold start can take several minutes.',
        'loading_models' => 'Downloading and loading the material models. This can take longer than generation itself.',
        'preparing_photo' => 'Cropping and sizing your photograph for material estimation.',
        'cleaning_photo' => 'Reducing photographed glare before estimating the surface.',
        'estimating_material' => 'Estimating base colour, normals, roughness and metalness.',
        'uploading_maps' => 'Saving and validating the five material maps.',
    ];
@endphp
<div @if(!$run->terminal()) wire:poll.5s="poll" @endif wire:key="synthesis-{{ $run->id }}" class="space-y-3 rounded-2xl border border-zinc-200 p-4 dark:border-zinc-700" data-test="generation-progress">
    <div class="flex items-center justify-between gap-3">
        <div role="status" aria-live="polite">
            <p class="font-semibold">{{ match($run->status) { 'succeeded' => 'Material ready', 'failed' => 'Generation failed', 'cancelled' => 'Generation cancelled', default => $run->stageLabel().'…' } }}</p>
            <p class="text-xs text-zinc-500">{{ intdiv($elapsed, 60) }}m {{ $elapsed % 60 }}s {{ $run->terminal() ? 'total' : 'elapsed' }} · Version #{{ $run->studio_revision_id }}</p>
        </div>
        @if(!$run->terminal())<flux:button wire:click="cancel" size="sm">Cancel generation</flux:button>@endif
    </div>
    @if($run->studio_revision_id !== $currentRevision)<p class="text-sm text-amber-700 dark:text-amber-300">This generation belongs to an earlier version. {{ $run->terminal() ? 'Open it in version history to inspect the result.' : 'Its result will appear in version history.' }}</p>@endif
    @if($run->error)<p role="alert" class="text-sm text-amber-700 dark:text-amber-300">{{ $run->error }}</p>@endif
    @if(!$run->terminal())
        <p class="text-sm text-zinc-500">{{ $hints[$run->stage] ?? 'Working on your material.' }} You can leave and return.</p>
        @if(isset($progress['completed'], $progress['total']))
            <div role="progressbar" aria-label="{{ $run->stageLabel() }} map progress" aria-valuemin="0" aria-valuemax="{{ $progress['total'] }}" aria-valuenow="{{ $progress['completed'] }}" class="h-2 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700"><div class="h-full rounded-full bg-emerald-600 transition-all" style="width: {{ 100 * $progress['completed'] / max(1, $progress['total']) }}%"></div></div>
            <p class="text-xs text-zinc-500">{{ $progress['completed'] }} of {{ $progress['total'] }} maps {{ $run->stage === 'uploading_maps' ? 'saved' : 'estimated' }}@if(isset($progress['role'])) · {{ ucfirst(str_replace('_', ' ', $progress['role'])) }}@endif</p>
        @else
            <div role="progressbar" aria-label="{{ $run->stageLabel() }} in progress" class="h-2 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700"><div class="h-full w-1/3 animate-pulse rounded-full bg-emerald-600"></div></div>
        @endif
        @if($run->heartbeat_at)
            <p class="text-xs text-zinc-500">Worker last reported {{ $run->heartbeat_at->diffForHumans() }}.@if($run->heartbeat_at->lt(now()->subMinutes(2))) Updates are delayed; the worker may be stalled.@endif</p>
        @else
            <p class="text-xs text-zinc-500">Waiting for the worker to connect. Generation has not started yet.</p>
        @endif
    @endif
    <ol class="grid grid-cols-2 gap-2 text-xs sm:grid-cols-4" aria-label="Generation stages">
        @foreach($stages as $key => $label)
            <li class="rounded-lg px-2 py-2 {{ $stageIndex !== false && $loop->index <= $stageIndex ? 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200' : 'bg-zinc-100 text-zinc-500 dark:bg-zinc-800' }}" @if($key === $run->stage) aria-current="step" @endif>{{ $stageIndex !== false && $loop->index < $stageIndex ? '✓' : $loop->iteration.'.' }} {{ $label }}</li>
        @endforeach
    </ol>
    @if(!$run->terminal())
        <div class="flex gap-3">
            @foreach(['prepared' => 'Prepared photo', 'cleaned' => 'Glare cleanup'] as $role => $label)
                @if(isset($run->artifacts[$role]))<figure class="w-24"><img alt="{{ $label }}" src="{{ route('studio-artifacts.show', ['revision' => $run->studio_revision_id, 'role' => $role]) }}" class="aspect-square rounded-lg object-cover" /><figcaption class="mt-1 text-xs text-zinc-500">{{ $label }}</figcaption></figure>@endif
            @endforeach
        </div>
    @endif
</div>
