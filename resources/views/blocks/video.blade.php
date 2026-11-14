{{--
    Kami's Video Block: a link to the provider, which public/vendor/kami/video.js turns into its player on a click (ADR 0016).
    The script tag comes once per page, however many videos; `@once` lasts the page's render.
--}}
@php($video = \Psyao\Kami\Content\Blocks\VideoLink::of($url))
@if ($video)
    @php($titled = filled($caption))
    <figure class="kami-video">
        <div class="kami-video-facade" data-kami-video="{{ $video->player }}" data-title="{{ $titled ? $caption : __('kami::kami-blocks.video.title', ['provider' => $video->provider]) }}">
            <a href="{{ $video->watch }}">{{ $titled ? __('kami::kami-blocks.video.watch_titled', ['title' => $caption, 'provider' => $video->provider]) : __('kami::kami-blocks.video.watch', ['provider' => $video->provider]) }}</a>
        </div>
        @if ($titled)
            <figcaption>{{ $caption }}</figcaption>
        @endif
    </figure>
    @once
        <script type="module" src="{{ asset(\Psyao\Kami\Content\Blocks\Video::SCRIPT) }}"></script>
    @endonce
@endif
