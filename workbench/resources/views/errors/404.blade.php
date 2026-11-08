{{-- In the site layout as the starter kit's 4xx views: its head from `x-kami::seo`. --}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <x-kami::seo :title="__('Not Found')" />
</head>
<body>
    <h1>{{ __('Not Found') }}</h1>
</body>
</html>
