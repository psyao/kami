<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <title>{{ $entry->title }}</title>
</head>
<body>
    <x-kami::preview-banner />
    <main>
        <h1>{{ $entry->title }}</h1>
        <div @if ($lang = $entry->fallbackLocale('body'))lang="{{ $lang }}"@endif>{{ $entry->body }}</div>
    </main>
    <footer>{{ \Workbench\App\Content\Footer::entry()?->tagline }}</footer>
</body>
</html>
