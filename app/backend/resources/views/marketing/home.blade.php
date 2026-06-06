@extends('layouts.hermes-desktop')

@section('content')
    @php
        $assetBase = 'img/desktop';
        $downloadAnchor = '#downloads';
        $externalLinks = [
            'nous' => 'https://nousresearch.com',
            'docs' => '/docs',
            'discord' => 'https://discord.gg/nousresearch',
            'github' => 'https://github.com/NousResearch/hermes-agent',
            'portal' => 'https://portal.nousresearch.com',
            'plans' => 'https://portal.nousresearch.com/manage-subscription',
            'video' => 'https://hermes-assets.nousresearch.com/hermes-desktop.mp4',
            'mac' => 'https://hermes-assets.nousresearch.com/Hermes-Setup.dmg',
            'windows' => 'https://hermes-assets.nousresearch.com/Hermes-Setup.exe',
        ];
        $platforms = [
            [
                'name' => 'Mac OS',
                'requirement' => 'macOS 12+',
                'button' => 'Download',
                'href' => $externalLinks['mac'],
                'image' => 'platform-art-mac.webp',
            ],
            [
                'name' => 'Windows',
                'requirement' => 'Windows 10/11',
                'button' => 'Download',
                'href' => $externalLinks['windows'],
                'image' => 'platform-art-windows.webp',
            ],
            [
                'name' => 'Linux',
                'requirement' => 'Any distro',
                'button' => 'Install via terminal',
                'href' => $externalLinks['docs'],
                'image' => 'platform-art-linux.webp',
            ],
        ];
        $features = [
            [
                'number' => 1,
                'label' => 'Connect',
                'heading' => 'Lives Everywhere',
                'body' => 'Telegram, Discord, Slack, WhatsApp, Signal, Email, CLI — and a growing list of platforms. One agent, one memory, every surface.',
                'image' => 'feature-connect.webp',
            ],
            [
                'number' => 2,
                'label' => 'Remember',
                'heading' => 'Persistent Memory',
                'body' => 'It learns your projects, auto-generates skills, and never forgets how it solved a problem.',
                'image' => 'feature-memory.webp',
            ],
            [
                'number' => 3,
                'label' => 'Schedule',
                'heading' => 'Focused Automation',
                'body' => 'Natural-language scheduling for reports, backups, and briefings — running unattended through the gateway, focused every time.',
                'image' => 'feature-automation.webp',
            ],
            [
                'number' => 4,
                'label' => 'Delegate',
                'heading' => 'Tasks Multiplied',
                'body' => 'Isolated subagents with their own conversations, terminals, and Python RPC scripts for zero-context-cost pipelines.',
                'image' => 'feature-tasks.webp',
            ],
            [
                'number' => 5,
                'label' => 'Search',
                'heading' => 'Browse the Web',
                'body' => 'Web search, browser automation, vision, image generation, text-to-speech, and multi-model reasoning.',
                'image' => 'feature-browse.webp',
            ],
            [
                'number' => 6,
                'label' => 'Experiment',
                'heading' => 'Isolated Sandboxing',
                'body' => 'Five backends — local, Docker, SSH, Singularity, Modal — with container hardening and namespace isolation.',
                'image' => 'feature-sandbox.webp',
            ],
        ];
    @endphp

    <nav class="hermes-navbar" id="navbar">
        <div class="hermes-nav-inner">
            <div class="hermes-nav-left">
                <a class="hermes-brand" href="{{ $externalLinks['nous'] }}">Nous</a>
                <a href="{{ $externalLinks['docs'] }}">Docs</a>
                <a class="is-active" href="/desktop">Hermes Agent</a>
            </div>

            <div class="hermes-nav-right">
                <a class="hermes-icon-link" href="{{ $externalLinks['discord'] }}" aria-label="Discord">
                    <svg aria-hidden="true" viewBox="0 0 24 24">
                        <path d="M20.3 5.2A16.9 16.9 0 0 0 16.1 4l-.2.4c1.5.4 2.2 1 2.2 1a13.3 13.3 0 0 0-12.2 0s.8-.7 2.4-1.1L8.1 4a17.2 17.2 0 0 0-4.3 1.2C1.1 9.2.3 13.1.7 17a17.1 17.1 0 0 0 5.3 2.7l.7-1.1a7 7 0 0 1-1.2-.6l.3-.2a12 12 0 0 0 12.4 0l.3.2c-.4.2-.8.5-1.3.6l.7 1.1A17.1 17.1 0 0 0 23.3 17c.5-4.5-.8-8.4-3-11.8ZM8.4 14.6c-.9 0-1.7-.8-1.7-1.8S7.5 11 8.4 11s1.8.8 1.8 1.8-.8 1.8-1.8 1.8Zm7.2 0c-.9 0-1.7-.8-1.7-1.8s.8-1.8 1.7-1.8 1.8.8 1.8 1.8-.8 1.8-1.8 1.8Z"/>
                    </svg>
                </a>
                <a class="hermes-icon-link" href="{{ $externalLinks['github'] }}" aria-label="GitHub">
                    <svg aria-hidden="true" viewBox="0 0 24 24">
                        <path d="M12 1.8a10.2 10.2 0 0 0-3.2 19.9c.5.1.7-.2.7-.5v-1.8c-2.9.6-3.5-1.2-3.5-1.2-.5-1.1-1.1-1.4-1.1-1.4-.9-.6.1-.6.1-.6 1 0 1.6 1.1 1.6 1.1.9 1.6 2.5 1.1 3 .8.1-.7.4-1.1.7-1.4-2.3-.3-4.7-1.2-4.7-5a4 4 0 0 1 1-2.7 3.7 3.7 0 0 1 .1-2.7s.9-.3 2.8 1a9.5 9.5 0 0 1 5 0c2-1.3 2.8-1 2.8-1 .6 1.4.2 2.4.1 2.7a4 4 0 0 1 1.1 2.8c0 3.8-2.4 4.7-4.7 4.9.4.3.8 1 .8 2v2.9c0 .3.2.6.8.5A10.2 10.2 0 0 0 12 1.8Z"/>
                    </svg>
                </a>
                <a class="hermes-button hermes-button-outline" href="{{ $externalLinks['portal'] }}">Portal</a>
                <a class="hermes-button hermes-button-primary hermes-button-small" href="{{ $downloadAnchor }}">Install</a>
            </div>
        </div>
    </nav>

    <main>
        <section class="hermes-hero" id="hero">
            <img class="hermes-hero-texture" src="{{ asset($assetBase.'/filler-bg0.webp') }}" alt="" aria-hidden="true">
            <div class="hermes-container hermes-hero-inner">
                <div class="hermes-hero-art-wrap">
                    <img class="hermes-hero-art" src="{{ asset($assetBase.'/hero-art.webp') }}" alt="Hermes desktop app window floating in a black space with blue interface glow">
                </div>

                <div class="hermes-hero-copy">
                    <div class="hermes-badge">
                        <span aria-hidden="true"></span>
                        Open Source &bull; MIT License
                    </div>
                    <h1>
                        <span>The Agent</span>
                        <span>That Grows</span>
                        <span>With You</span>
                    </h1>
                    <a class="hermes-button hermes-button-primary hermes-hero-cta" href="{{ $downloadAnchor }}" data-scramble-target="Download Now" aria-label="Download Now">Download Now</a>
                </div>

                <div class="hermes-video-shell">
                    <video class="hermes-hero-video" autoplay loop muted playsinline preload="metadata" poster="{{ asset($assetBase.'/hero-art.webp') }}" aria-label="Hermes Desktop product video">
                        <source src="{{ $externalLinks['video'] }}" type="video/mp4">
                    </video>
                </div>
            </div>
        </section>

        <section class="hermes-downloads" id="downloads" aria-labelledby="downloads-title">
            <div class="hermes-container hermes-download-grid">
                <h2 id="downloads-title" class="hermes-visually-hidden">Downloads</h2>
                @foreach ($platforms as $platform)
                    <article class="hermes-platform-card">
                        <img src="{{ asset($assetBase.'/'.$platform['image']) }}" alt="" aria-hidden="true" loading="lazy">
                        <p>{{ $platform['requirement'] }}</p>
                        <h3>{{ $platform['name'] }}</h3>
                        <a class="hermes-button hermes-button-outline" href="{{ $platform['href'] }}">{{ $platform['button'] }}</a>
                    </article>
                @endforeach
            </div>
        </section>

        <section class="hermes-features" id="features" aria-label="Hermes Desktop features">
            @foreach ($features as $feature)
                <article class="hermes-feature-row">
                    <div class="hermes-container hermes-feature-inner">
                        <div class="hermes-feature-content">
                            <span class="hermes-feature-number">#{{ $feature['number'] }}</span>
                            <p class="hermes-feature-label">{{ $feature['label'] }}</p>
                            <h2>{{ $feature['heading'] }}</h2>
                            <p>{{ $feature['body'] }}</p>
                        </div>
                        <div class="hermes-feature-image-wrap">
                            <img src="{{ asset($assetBase.'/'.$feature['image']) }}" alt="{{ $feature['heading'] }} Hermes Desktop screenshot" loading="lazy">
                        </div>
                    </div>
                </article>
            @endforeach
        </section>

        <section class="hermes-portal" id="portal" aria-labelledby="portal-title">
            <div class="hermes-container hermes-portal-inner">
                <div class="hermes-portal-copy">
                    <p class="hermes-overline">Hermes</p>
                    <h2 id="portal-title">Nous Portal</h2>
                    <p class="hermes-tier-string">Free &bull; Plus &bull; Super &bull; Ultra</p>
                    <p>All paid tiers include monthly credits for use in Hermes Agent, access to 300+ cutting-edge models and built-in tool use</p>
                    <a class="hermes-inline-link" href="{{ $externalLinks['plans'] }}">View All Our Plans</a>
                </div>
                <img class="hermes-portal-figure" src="{{ asset($assetBase.'/portal-figure.webp') }}" alt="Nous Portal subscription dashboard floating on a dark background" loading="lazy">
            </div>
        </section>
    </main>

    <footer class="hermes-footer">
        <div class="hermes-container hermes-footer-inner">
            <div class="hermes-footer-brand">
                <img src="{{ asset($assetBase.'/nous.webp') }}" alt="" aria-hidden="true">
                <strong>Nous Research</strong>
                <p>Hermes Agent v0.15.2</p>
                <p>MIT License &middot; 2026</p>
            </div>
            <nav aria-label="Footer">
                <a href="{{ $externalLinks['docs'] }}">Docs</a>
                <a href="{{ $externalLinks['github'] }}">GitHub</a>
                <a href="{{ $externalLinks['discord'] }}">Discord</a>
                <a href="{{ $externalLinks['portal'] }}">Portal</a>
            </nav>
        </div>
    </footer>
@endsection
