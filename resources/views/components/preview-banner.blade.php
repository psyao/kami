{{-- Nothing outside an admin Preview, so a public page pays for one attribute read. --}}
@if (request()->attributes->getBoolean('kami.preview'))
    <aside class="kami-preview-banner" aria-label="{{ __('kami::admin.preview.label') }}">
        <p>{{ __('kami::admin.preview.banner') }}</p>
    </aside>
@endif
