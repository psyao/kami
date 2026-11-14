{!! '<' . '?xml version="1.0" encoding="UTF-8"?' . '>' !!}{{-- Split: Blade, and PHP under short_open_tag, would read the opening of the declaration as code; first, as nothing may precede it. --}}
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">
<channel>
<title>{{ $xmlSafe($title) }}</title>
<link>{{ $link }}</link>
<description>{{ $xmlSafe($description) }}</description>
<language>{{ $locale }}</language>
<atom:link href="{{ $self }}" rel="self" type="application/rss+xml"/>
@foreach ($entries as $entry)
<item>
<title>{{ $xmlSafe($entry->title) }}</title>
<link>{{ $entry->url() }}</link>
<guid isPermaLink="false">{{ $entry->id }}</guid>
@if ($entry->published_at !== null)
<pubDate>{{ $entry->published_at->toRssString() }}</pubDate>
@endif
@if (($summary = $entry->summary()) !== null)
<description>{{ $xmlSafe($summary) }}</description>
@endif
</item>
@endforeach
</channel>
</rss>
