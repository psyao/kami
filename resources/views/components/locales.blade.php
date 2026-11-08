{{-- The Locales the page's Entry is visible in, so no link leads to a missing Translation; nothing without an Entry or with fewer than two. --}}
@php($site = \Psyao\Kami\Sites\Internal\Site::current())
@php($entry = request()->attributes->get('kami.entry'))
@php($urls = $entry instanceof \Psyao\Kami\Content\Entry ? array_filter(array_combine($site->locales, array_map($entry->url(...), $site->locales))) : [])
@if (count($urls) > 1)
    <nav {{ $attributes->merge(['class' => 'kami-locales', 'aria-label' => __('kami::locales.label')]) }}>
        <ul>
            @foreach ($urls as $locale => $url)
                <li><a href="{{ $url }}" hreflang="{{ $locale }}" lang="{{ $locale }}"@if ($locale === $site->currentLocale()) aria-current="page"@endif>{{ \Illuminate\Support\Str::ucfirst((new \Carbon\Language($locale))->getNativeName()) }}</a></li>
            @endforeach
        </ul>
    </nav>
@endif
