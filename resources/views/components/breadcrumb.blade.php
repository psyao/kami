@props(['entry'])
{{-- The Entry's ancestors, root first, each linked, then the Entry itself as the current page. Nothing for a root Entry, nor under an ancestor that is not visible. --}}
@php($ancestors = $entry->ancestors())
@if ($ancestors->isNotEmpty())
    <nav {{ $attributes->merge(['class' => 'kami-breadcrumb', 'aria-label' => __('kami::breadcrumb.label')]) }}>
        <ol>
            @foreach ($ancestors as $ancestor)
                <li>@if ($url = $ancestor->url())<a href="{{ $url }}">{{ $ancestor->title }}</a>@else{{ $ancestor->title }}@endif</li>
            @endforeach
            <li>@if ($url = $entry->url())<a href="{{ $url }}" aria-current="page">{{ $entry->title }}</a>@else<span aria-current="page">{{ $entry->title }}</span>@endif</li>
        </ol>
    </nav>
@endif
