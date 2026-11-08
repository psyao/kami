@props(['title' => null, 'description' => null])
{{-- In place of the layout's <title>. The props serve a page Kami does not render, a site route's or an error page's; a Kami page has its own values. --}}
@php($seo = request()->attributes->get(\Psyao\Kami\Seo\Internal\ResolvePageMeta::ATTRIBUTE) ?? \Psyao\Kami\Seo\Internal\ResolvePageMeta::forSite($title, $description))
<title>{{ $seo->title }}</title>
@if ($seo->description !== null)
<meta name="description" content="{{ $seo->description }}">
@endif
@if ($seo->canonical !== null)
<link rel="canonical" href="{{ $seo->canonical }}">
<meta property="og:url" content="{{ $seo->canonical }}">
@endif
@if ($seo->robots !== null)
<meta name="robots" content="{{ $seo->robots }}">
@endif
