{{-- In the site layout as the starter kit's 4xx views: its head from `x-kami::seo`. --}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <x-kami::seo :title="__('Not Found')" />
    <link rel="stylesheet" href="/workbench.css">
</head>
<body>
    <main>
        <h1>404 {{ __('Not Found') }}</h1>
    </main>
</body>
</html>
