@extends('layouts.site')

@section('content')
    <section class="page-hero">
        <p class="eyebrow">Pricing</p>
        <h1>Simple monthly plans for <span class="hl">your kind of learning</span>.</h1>
        <p>
            Choose your monthly video minutes and how many videos you generate at once.
            Every plan includes model choice, learning tools, and lyrics correction.
        </p>
    </section>

    <section class="section" style="padding-top: 24px;">
        @include('marketing.partials.pricing-plans')
    </section>
@endsection
