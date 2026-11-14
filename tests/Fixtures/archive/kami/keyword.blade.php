<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <x-kami::seo />
</head>
<body>
    <main>
        <h1>{{ $term->title }}</h1>
        <ul>
            @foreach ($entries as $post)
                <li><a href="{{ $post->url() }}">{{ $post->title }}</a></li>
            @endforeach
        </ul>
        <x-kami::pagination :paginator="$entries" />
    </main>
</body>
</html>
