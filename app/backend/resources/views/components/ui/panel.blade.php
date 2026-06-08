@props([
    'title' => null,
    'description' => null,
])

<section {{ $attributes->class(['panel']) }}>
    @if ($title || $description)
        <div class="panel-heading">
            @if ($title)
                <h2>{{ $title }}</h2>
            @endif
            @if ($description)
                <p>{{ $description }}</p>
            @endif
        </div>
    @endif

    {{ $slot }}
</section>
