{{-- A Form's `<form>` posting to its route, with a honeypot and the page it sits on; nothing tied to a visitor, so the page stays cached (ADR 0015). --}}
@props(['form'])
@php($kamiForm = \Psyao\Kami\Facades\Kami::form($form))
@php($kamiEntry = request()->attributes->get('kami.entry'))
@php(throw_unless($kamiEntry instanceof \Psyao\Kami\Content\Entry, \LogicException::class, 'x-kami::form renders on a Kami page only; a form on a route of the site posts to a route of the site.'))
<form method="post" action="{{ route('kami.form', $kamiForm->handle(), absolute: false) }}" {{ $attributes->merge(['id' => $kamiForm->handle(), 'class' => 'kami-form']) }}>
    {{-- Under `Kami::routes(session: true)` the page and the form route run in the `web` group, which checks the token. --}}
    @php($kamiRoute = request()->route())
    @if ($kamiRoute instanceof \Illuminate\Routing\Route && in_array('web', array_diff($kamiRoute->gatherMiddleware(), $kamiRoute->excludedMiddleware()), true))
        @csrf
    @endif
    <input type="hidden" name="{{ \Psyao\Kami\Forms\Internal\SubmitForm::ORIGIN }}" value="{{ $kamiEntry->id }}:{{ \Psyao\Kami\Sites\Internal\Site::current()->currentLocale() }}">
    <div class="kami-form-honeypot" hidden>
        <label for="{{ $kamiForm->handle() }}-kami-website">{{ __('kami::forms.honeypot') }}</label>
        <input type="text" id="{{ $kamiForm->handle() }}-kami-website" name="{{ \Psyao\Kami\Forms\Internal\SubmitForm::HONEYPOT }}" tabindex="-1" autocomplete="off">
    </div>
    {{ $slot }}
</form>
