@props([
    'feature',
])

<div class="feature-row" data-reveal>
    <div class="feature-row-inner">
        <div class="feature-text">
            <span class="feature-num">{{ $feature['eyebrow'] }}</span>
            <h2>{{ $feature['title'] }}</h2>
            <p>{{ $feature['description'] }}</p>
        </div>
        <div class="feature-media">
            <img
                src="{{ asset($feature['image']) }}"
                alt="{{ $feature['alt'] }}"
                width="{{ $feature['width'] }}"
                height="{{ $feature['height'] }}"
                loading="{{ $feature['loading'] ?? 'lazy' }}"
                decoding="async"
            >
        </div>
    </div>
</div>
