{{-- One level of `<x-kami::menu>`, and the levels under it. --}}
<ul class="kami-menu-list">
    @foreach ($links as $link)
        <li class="kami-menu-item">
            @if ($link->url !== null)
                <a {{ new \Illuminate\View\ComponentAttributeBag(array_filter(['href' => $link->url, 'aria-current' => $link->isCurrent ? 'page' : ($link->inTrail ? 'true' : null), 'target' => $link->newTab ? '_blank' : null, 'rel' => $link->newTab ? 'noopener' : null])) }}>{{ $link->label }}</a>
            @else
                <span>{{ $link->label }}</span>
            @endif
            @if ($link->children !== [])
                @include('kami::menu-list', ['links' => $link->children])
            @endif
        </li>
    @endforeach
</ul>
