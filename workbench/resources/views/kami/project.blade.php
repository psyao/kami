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
    </main>
</body>
</html>
