<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <title>{{ $entry->title }}</title>
</head>
<body>
    <main>
        <h1>{{ $entry->title }}</h1>
        <p @if ($lang = $entry->fallbackLocale('summary'))lang="{{ $lang }}"@endif>{{ $entry->summary }}</p>
    </main>
</body>
</html>
