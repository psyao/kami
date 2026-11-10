{{-- Plain text: nothing here is HTML, so values are printed raw. --}}
{!! $intro !!}

@foreach ($fields as $label => $value)
{!! $label !!}:
{!! $value !!}

@endforeach
