{{-- After a refused Submission: a summary that takes focus on load, with no script, and links to each field by its id, the field's name. --}}
@props(['level' => 2])
{{-- ponytail: one bag per page, so two Forms on a page both show the refused one's errors; V1 names the Form here if a site needs two. --}}
@php($kamiErrors = isset($errors) ? $errors->getBag('default') : null)
@if ($kamiErrors?->any())
    <div {{ $attributes->merge(['class' => 'kami-form-errors', 'role' => 'alert', 'tabindex' => '-1']) }} autofocus>
        <h{{ (int) $level }} class="kami-form-errors-heading">{{ __('kami::forms.errors.heading') }}</h{{ (int) $level }}>
        <ul>
            @foreach ($kamiErrors->messages() as $field => $messages)
                <li><a href="#{{ $field }}">{{ $messages[0] }}</a></li>
            @endforeach
        </ul>
    </div>
@endif
