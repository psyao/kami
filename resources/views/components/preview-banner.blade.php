{{-- Nothing outside an admin Preview or a Preview link, so a public page pays for one attribute read. --}}
@if (request()->attributes->getBoolean('kami.preview'))
    <aside class="kami-preview-banner" aria-label="{{ __('kami::admin.preview.label') }}">
        <p>{{ request()->attributes->get('kami.preview.banner') ?? __('kami::admin.preview.banner') }}</p>
    </aside>
@endif
