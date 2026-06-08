@props([
    'errors',
])

@if ($errors->any())
    <ul class="error-list">
        @foreach ($errors->all() as $error)
            <li>{{ $error }}</li>
        @endforeach
    </ul>
@endif
