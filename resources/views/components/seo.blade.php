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
<meta property="og:title" content="{{ $seo->title }}">
@if ($seo->description !== null)
<meta property="og:description" content="{{ $seo->description }}">
@endif
<meta property="og:site_name" content="{{ $seo->siteName }}">
<meta property="og:type" content="{{ $seo->ogType }}">
@if ($seo->publishedTime !== null)
<meta property="article:published_time" content="{{ $seo->publishedTime->toIso8601String() }}">
@endif
@if ($seo->modifiedTime !== null)
<meta property="article:modified_time" content="{{ $seo->modifiedTime->toIso8601String() }}">
@endif
@if ($seo->image !== null)
<meta property="og:image" content="{{ $seo->image }}">
@if ($seo->imageWidth !== null)
<meta property="og:image:width" content="{{ $seo->imageWidth }}">
<meta property="og:image:height" content="{{ $seo->imageHeight }}">
@endif
@if ($seo->imageAlt !== null)
<meta property="og:image:alt" content="{{ $seo->imageAlt }}">
@endif
@endif
<meta name="twitter:card" content="{{ $seo->image === null ? 'summary' : 'summary_large_image' }}">
