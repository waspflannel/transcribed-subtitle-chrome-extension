@extends('layouts.site')

@section('content')
    @php
        $assetBase = 'img/marketing/dark-academia';
        $downloadHref = '#download';
        $videoSources = [];
        $webmDemoPath = 'video/marketing/extension-demo.webm';
        $mp4DemoPath = 'video/marketing/extension-demo.mp4';

        if (file_exists(public_path($webmDemoPath))) {
            $videoSources[] = ['src' => asset($webmDemoPath), 'type' => 'video/webm'];
        }

        if (file_exists(public_path($mp4DemoPath))) {
            $videoSources[] = ['src' => asset($mp4DemoPath), 'type' => 'video/mp4'];
        }

        $perks = [
            [
                'label' => '01',
                'title' => 'Perk 1',
                'body' => 'Placeholder body for the first approved language-learning benefit.',
                'image' => 'perk-missing-captions.png',
            ],
            [
                'label' => '02',
                'title' => 'Perk 2',
                'body' => 'Placeholder body for the second approved language-learning benefit.',
                'image' => 'perk-translation-layer.png',
            ],
            [
                'label' => '03',
                'title' => 'Perk 3',
                'body' => 'Placeholder body for the third approved language-learning benefit.',
                'image' => 'perk-word-cards.png',
            ],
            [
                'label' => '04',
                'title' => 'Perk 4',
                'body' => 'Placeholder body for the fourth approved language-learning benefit.',
                'image' => null,
            ],
            [
                'label' => '05',
                'title' => 'Perk 5',
                'body' => 'Placeholder body for the fifth approved language-learning benefit.',
                'image' => null,
            ],
            [
                'label' => '06',
                'title' => 'Perk 6',
                'body' => 'Placeholder body for the sixth approved language-learning benefit.',
                'image' => null,
            ],
        ];
    @endphp

    <section class="hero hero-editorial">
        <img class="hero-image" src="{{ asset($assetBase.'/hero-language-desk.png') }}" alt="" aria-hidden="true">
        <div class="hero-scrim" aria-hidden="true"></div>
        <div class="hero-copy">
            <p class="eyebrow">Paid beta for YouTube language learners</p>
            <h1>Transcribed Subtitle Extension</h1>
            <p class="hero-lede">learn languages using youtube</p>
            <div class="hero-actions">
                <a class="button" href="{{ $downloadHref }}">Download extension</a>
            </div>
        </div>
    </section>

    <section class="section product-video-section" aria-labelledby="product-video-title">
        <div class="section-heading section-heading-centered">
            <p class="eyebrow">Product video</p>
            <h2 id="product-video-title">The study layer belongs center stage.</h2>
            <p>A local MP4 or WebM demo can replace this generated poster when the extension-in-use recording is ready.</p>
        </div>

        <div class="video-showcase">
            <video
                class="product-video"
                controls
                preload="metadata"
                playsinline
                poster="{{ asset($assetBase.'/product-video-poster.png') }}"
                aria-label="Transcribed Subtitle Extension demo video"
            >
                @foreach ($videoSources as $source)
                    <source src="{{ $source['src'] }}" type="{{ $source['type'] }}">
                @endforeach
                Your browser can display the generated poster until the demo video file is available.
            </video>
            <div class="video-frame-meta" aria-hidden="true">
                <span>MP4/WebM slot ready</span>
                <span>Generated poster fallback</span>
            </div>
        </div>
    </section>

    <section class="why-section" aria-labelledby="why-title">
        <div class="why-intro">
            <p class="eyebrow">Why use it</p>
            <h2 id="why-title">Six placeholder perk slots for approved claims.</h2>
        </div>

        <div class="perk-grid">
            @foreach ($perks as $perk)
                <article class="perk-card">
                    @if ($perk['image'])
                        <img src="{{ asset($assetBase.'/'.$perk['image']) }}" alt="" aria-hidden="true">
                    @else
                        <div class="perk-index" aria-hidden="true">{{ $perk['label'] }}</div>
                    @endif
                    <div>
                        <span>{{ $perk['label'] }}</span>
                        <h3>{{ $perk['title'] }}</h3>
                        <p>{{ $perk['body'] }}</p>
                    </div>
                </article>
            @endforeach
        </div>
    </section>

    <section class="section final-cta final-cta-image" id="download">
        <div class="final-copy">
            <p class="eyebrow">Download</p>
            <h2>Bring the reading room to your next YouTube lesson.</h2>
            <p>
                The public download link is a placeholder until the Chrome Web Store or beta install path is ready.
            </p>
            <a class="button" href="{{ $downloadHref }}">Download extension</a>
            <a class="button button-secondary" href="{{ route('marketing.pricing') }}">See pricing</a>
        </div>
        <img src="{{ asset($assetBase.'/final-reading-room.png') }}" alt="" aria-hidden="true">
    </section>
@endsection
