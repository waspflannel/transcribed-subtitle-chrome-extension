@props(['image', 'width', 'height', 'title', 'alt', 'steps', 'wide' => false])

<figure @class(['guide-shot', 'guide-shot-wide' => $wide])>
    <a class="guide-shot-link" href="{{ asset('img/guide/'.$image) }}" target="_blank" rel="noopener" aria-label="{{ __(':title — open full-size image in a new tab', ['title' => $title]) }}">
        <span class="guide-shot-image">
            <img src="{{ asset('img/guide/'.$image) }}" width="{{ $width * 2 }}" height="{{ $height * 2 }}" alt="{{ $alt }}" loading="lazy" decoding="async">
            <svg class="guide-shot-marks" viewBox="0 0 {{ $width }} {{ $height }}" aria-hidden="true" focusable="false">
                @foreach ($steps as $step)
                    <rect class="guide-shot-outline" x="{{ $step['x'] }}" y="{{ $step['y'] }}" width="{{ $step['w'] }}" height="{{ $step['h'] }}" rx="5" />
                    <circle class="guide-shot-pin" cx="{{ $step['cx'] }}" cy="{{ $step['cy'] }}" r="11" />
                    <text class="guide-shot-number" x="{{ $step['cx'] }}" y="{{ $step['cy'] }}" dy=".35em" text-anchor="middle">{{ $loop->iteration }}</text>
                @endforeach
            </svg>
        </span>
        <span class="guide-shot-zoom">{{ __('Open full-size image') }} <span aria-hidden="true">↗</span></span>
    </a>
    <figcaption>
        <p class="eyebrow">{{ __('On your screen') }}</p>
        <h3>{{ $title }}</h3>
        <ol class="guide-callouts" role="list">
            @foreach ($steps as $step)
                <li><span class="guide-callout-number" aria-hidden="true">{{ $loop->iteration }}</span><span>{{ $step['text'] }}</span></li>
            @endforeach
        </ol>
    </figcaption>
</figure>
