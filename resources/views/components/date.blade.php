@props(['value', 'format' => 'LL', 'day' => false])
{{-- Nothing for a null value, so an empty optional Field needs no @if. A `day`, as a Date Field holds, has no time and so no time zone to shift it. --}}
@if ($value !== null)
    @php($date = \Carbon\CarbonImmutable::instance($value)->locale(app()->getLocale()))
    <time {{ $attributes->merge([
        'class' => 'kami-date',
        'datetime' => $day ? $date->format('Y-m-d') : $date->toIso8601ZuluString(),
    ]) }}>{{ ($day ? $date : $date->setTimezone(\Psyao\Kami\Sites\Internal\Site::current()->timezone))->isoFormat($format) }}</time>
@endif
