{{-- Self-contained as the starter kit's 5xx views: no database, no Vite manifest, no JS, a static stylesheet. --}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta name="robots" content="noindex">
    <link rel="stylesheet" href="/errors.css">
    <title>{{ $exception->getStatusCode() }}</title>
</head>
<body>
    <h1>{{ $exception->getStatusCode() }}</h1>
</body>
</html>
