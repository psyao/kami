{{-- BlocksTest's Declaring Block: its typed data, one line each. --}}
<div class="declaring">
    <p class="related">{{ $related->pluck('title')->join(', ') }}</p>
    <div class="note">{{ $note }}</div>
    <p class="day">{{ $day?->toDateString() }}</p>
    <p class="gallery">{{ $gallery->count() }}</p>
</div>
