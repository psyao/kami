{{-- The Callout Block: its data by Field handle, typed. --}}
<aside class="callout callout-{{ $tone ?? 'info' }}">
    <x-kami::image :media="$image" preset="card" />
    <p>{{ $text }}</p>
</aside>
