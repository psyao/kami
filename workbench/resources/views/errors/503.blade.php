{{-- Self-contained as the starter kit's 5xx views: no database, no Vite manifest, no JS. --}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta name="robots" content="noindex">
    <title>{{ $exception->getStatusCode() }}</title>
</head>
<body>
    <main>
        <h1>{{ $exception->getStatusCode() }}</h1>
    </main>
</body>
</html>
