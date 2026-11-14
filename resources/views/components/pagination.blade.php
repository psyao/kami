@props(['paginator'])
{{-- A cursor's previous and next links: a listing's page has no number. Nothing on a single page. --}}
@if ($paginator->hasPages())
    <nav {{ $attributes->merge(['class' => 'kami-pagination', 'aria-label' => __('kami::pagination.label')]) }}>
        <ul>
            @if ($paginator->previousPageUrl() !== null)
                <li><a href="{{ $paginator->previousPageUrl() }}" rel="prev">{{ __('kami::pagination.previous') }}</a></li>
            @endif
            @if ($paginator->nextPageUrl() !== null)
                <li><a href="{{ $paginator->nextPageUrl() }}" rel="next">{{ __('kami::pagination.next') }}</a></li>
            @endif
        </ul>
    </nav>
@endif
