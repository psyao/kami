<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <title>{{ $entry->title }}</title>
</head>
<body>
    <main>
        <h1>{{ $entry->title }}</h1>
        <div @if ($lang = $entry->fallbackLocale('body'))lang="{{ $lang }}"@endif>{{ $entry->body }}</div>
    </main>
</body>
</html>
