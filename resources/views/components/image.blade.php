@props(['media', 'preset', 'sizes' => null, 'eager' => false])
{{-- Nothing for a null Media item, so an empty optional Field needs no @if. --}}
@php($picture = \Psyao\Kami\Media\Internal\Picture::of($media, $preset, $sizes))
@if ($picture)
    <picture class="kami-image">
        @foreach ($picture->sources as $type => $srcset)
            <source type="{{ $type }}" srcset="{{ $srcset }}"@if ($picture->sizes !== null) sizes="{{ $picture->sizes }}"@endif>
        @endforeach
        <img {{ $attributes->merge([
            'src' => $picture->src,
            'srcset' => $picture->srcset,
            'sizes' => $picture->sizes,
            'width' => $picture->width,
            'height' => $picture->height,
            'alt' => $picture->alt,
            'lang' => $picture->lang,
            'loading' => $eager ? null : 'lazy',
            'decoding' => 'async',
            'fetchpriority' => $eager ? 'high' : null,
        ]) }}>
    </picture>
@endif
