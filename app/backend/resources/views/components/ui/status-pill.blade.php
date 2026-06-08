@props([
    'status',
])

<span {{ $attributes->class(['status-pill', 'status-'.$status]) }}>{{ $slot->isEmpty() ? $status : $slot }}</span>
