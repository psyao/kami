<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <title>{{ $entry->title }}</title>
</head>
<body>
    <x-kami::preview-banner />
    <header>
        <x-kami::locales />
    </header>
    <main>
        <h1>{{ $entry->title }}</h1>
        <p><x-kami::date :value="$entry->published_at" /></p>
        <p{{ $entry->langAttribute('summary') }}>{{ $entry->summary }}</p>
        {{ $entry->body }}
    </main>
</body>
</html>
