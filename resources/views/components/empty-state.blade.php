@props(['icon' => 'document', 'title' => null, 'description' => null])
<div {{ $attributes->class('empty-state') }}>
    <div class="empty-state-inner">
        <span class="empty-state-icon"><x-icon :name="$icon" size="lg" /></span>
        <h2>{{ $title ?: __('ui.empty.title') }}</h2>
        @if($description)<p>{{ $description }}</p>@endif
        @if(trim((string) $slot) !== '')<div class="cluster">{{ $slot }}</div>@endif
    </div>
</div>
