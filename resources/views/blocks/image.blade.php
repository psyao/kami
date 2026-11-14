{{-- Kami's Image Block: nothing once its Media item is gone. A site overrides it in resources/views/vendor/kami/blocks/image.blade.php. --}}
@if ($image)
    <figure class="kami-figure">
        <x-kami::image :media="$image" preset="kami-block" />
        @if (filled($caption))
            <figcaption>{{ $caption }}</figcaption>
        @endif
    </figure>
@endif
