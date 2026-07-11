@extends('layouts.site')

@section('content')
    <section class="page-hero">
        <p class="eyebrow">Pricing</p>
        <h1>Simple monthly plans, metered in <span class="hl">generated-video minutes</span>.</h1>
        <p>
            Pick the queue speed, monthly minute cap, and learning depth that match your YouTube study volume.
            Checkout is hosted by Stripe, and billing changes are managed from the account dashboard.
        </p>
    </section>

    <section class="section" style="padding-top: 24px;">
        @include('marketing.partials.pricing-plans')
    </section>
@endsection
