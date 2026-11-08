<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <x-kami::seo />
</head>
<body>
    <x-kami::preview-banner />
    <main>
        <h1>{{ $entry->title }}</h1>
        <p>{{ $entry->colour }}</p>
    </main>
</body>
</html>
