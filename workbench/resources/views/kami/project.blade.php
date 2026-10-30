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
    </main>
</body>
</html>
