@props([
    'status',
])

<span {{ $attributes->class(['status-pill', 'status-'.$status]) }}>{{ $slot->isEmpty() ? __($status) : $slot }}</span>
