<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <title>{{ $entry->title }}</title>
</head>
<body>
    <main>
        <h1>{{ $entry->title }}</h1>
        <p>{{ $entry->client }}</p>
        @if ($entry->links)
            <ul>
                @foreach ($entry->links as $link)
                    <li><a href="{{ $link['url'] }}">{{ $link['label'] }}</a></li>
                @endforeach
            </ul>
        @endif
        @if ($entry->address)
            <address>{{ $entry->address['street'] }}, {{ $entry->address['city'] }}</address>
        @endif
        @if ($entry->related->isNotEmpty())
            <ul>
                @foreach ($entry->related as $related)
                    <li><a href="{{ $related->url() }}">{{ $related->title }}</a></li>
                @endforeach
            </ul>
        @endif
        @if ($entry->tags->isNotEmpty())
            <ul>
                @foreach ($entry->tags as $tag)
                    <li>{{ $tag->title }}</li>
                @endforeach
            </ul>
        @endif
    </main>
</body>
</html>
