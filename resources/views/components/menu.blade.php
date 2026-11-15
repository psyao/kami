@props(['handle'])
{{-- A Menu's visible links, nested, the current page and its Trail marked (ADR 0025); no dropdown, no script: that behaviour is the site's. Nothing for an empty Menu. --}}
@php($links = \Psyao\Kami\Facades\Kami::menu($handle))
@if ($links !== [])
    <nav {{ $attributes->merge(['class' => 'kami-menu', 'aria-label' => \Psyao\Kami\Menus\Internal\Menus::label($handle)]) }}>
        @include('kami::menu-list', ['links' => $links])
    </nav>
@endif
