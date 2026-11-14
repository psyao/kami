<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <x-kami::seo />
    <link rel="stylesheet" href="/workbench.css">
</head>
<body>
    <header>
        <x-kami::locales />
    </header>
    <main>
        <h1>{{ \Illuminate\Support\Str::ucfirst(__('kami-types.article.plural')) }}</h1>
        <ul>
            @foreach ($entries as $article)
                <li>
                    <a href="{{ $article->url() }}">{{ $article->title }}</a>
                    <x-kami::date :value="$article->published_at" />
                </li>
            @endforeach
        </ul>
        <x-kami::pagination :paginator="$entries" />
    </main>
</body>
</html>
