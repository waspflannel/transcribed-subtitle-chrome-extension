@extends('layouts.site')

@section('content')
    <section class="page-hero">
        <p class="eyebrow">{{ __('Pricing') }}</p>
        <h1>{!! strtr(e(__('Simple monthly plans for :slot1:your kind of learning:slot2:.')), [':slot1:' => '<span class="hl">', ':slot2:' => '</span>']) !!}</h1>
        <p>{{ __('Choose your monthly video minutes and how many videos you generate at once. Every plan includes model choice, learning tools, and lyrics correction.') }}</p>
    </section>

    <section class="section" style="padding-top: 24px;">
        @include('marketing.partials.pricing-plans')
    </section>
@endsection
