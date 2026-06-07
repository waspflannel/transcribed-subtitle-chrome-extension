@extends('layouts.site')

@section('content')
    <section class="page-hero">
        <p class="eyebrow">Language coverage</p>
        <h1>Supported subtitle and translation languages.</h1>
        <p>
            The catalog mirrors the backend contract used by the extension. Auto detect is available for subtitle generation, while target languages use concrete language choices.
        </p>
    </section>

    <section class="section">
        <div class="language-groups">
            @foreach ($languageGroups as $group)
                <section class="language-group" data-reveal data-reveal-delay="{{ $loop->index * 80 }}">
                    <div>
                        <h2>{{ $group['label'] }}</h2>
                        <p>{{ $group['description'] }}</p>
                    </div>
                    <ul class="language-cloud">
                        @foreach ($group['languages'] as $language)
                            <li>{{ $language['label'] }}</li>
                        @endforeach
                    </ul>
                </section>
            @endforeach
        </div>
    </section>
@endsection
