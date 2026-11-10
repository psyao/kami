{{-- Plain text: nothing here is HTML, so values are printed raw. --}}
{!! $intro !!}

@foreach ($fields as $field)
{!! $field['label'] !!}:
{!! $field['value'] !!}

@endforeach
@if ($link !== null)
{!! $link !!}
@endif
