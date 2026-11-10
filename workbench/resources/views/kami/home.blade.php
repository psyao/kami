<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <x-kami::seo />
</head>
<body>
    <x-kami::preview-banner />
    <main>
        <h1>{{ $entry->title }}</h1>
        <p>{{ $entry->intro }}</p>

        <section aria-labelledby="contact-heading">
            <h2 id="contact-heading">{{ __('kami-forms.contact.label') }}</h2>
            @if (\Psyao\Kami\Facades\Kami::form('contact')->sent())
                <p id="contact" role="status">{{ __('kami-forms.contact.sent') }}</p>
            @else
                <x-kami::form form="contact">
                    <x-kami::form.errors />
                    @foreach (['name' => 'text', 'email' => 'email'] as $field => $type)
                        <p>
                            <label for="{{ $field }}">{{ ucfirst(__("validation.attributes.{$field}")) }}</label>
                            <input type="{{ $type }}" id="{{ $field }}" name="{{ $field }}" value="{{ old($field) }}" required @error($field) aria-invalid="true" aria-describedby="{{ $field }}-error" @enderror>
                            @error($field)<span id="{{ $field }}-error">{{ $message }}</span>@enderror
                        </p>
                    @endforeach
                    <p>
                        <label for="message">{{ ucfirst(__('validation.attributes.message')) }}</label>
                        <textarea id="message" name="message" required @error('message') aria-invalid="true" aria-describedby="message-error" @enderror>{{ old('message') }}</textarea>
                        @error('message')<span id="message-error">{{ $message }}</span>@enderror
                    </p>
                    <p><button type="submit">{{ __('kami-forms.contact.send') }}</button></p>
                </x-kami::form>
            @endif
        </section>
    </main>
</body>
</html>
