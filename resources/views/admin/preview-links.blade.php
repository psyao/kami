{{-- The Translation's active Preview links, inside the action's modal; the form below it creates one. --}}
@if ($links->isEmpty())
    <p>{{ __('kami::admin.preview_links.none') }}</p>
@else
    <ul class="kami-preview-links">
        @foreach ($links as $link)
            <li>
                {{ $link['label'] }}
                {{ ($action->getModalAction('revokePreviewLink'))(['link' => $link['id']]) }}
            </li>
        @endforeach
    </ul>
@endif
