<details class="locale-switcher">
    <summary aria-label="{{ __('Interface language') }}">{{ config('localization.locales')[app()->getLocale()] }}</summary>
    <nav aria-label="{{ __('Interface language') }}">
        @foreach (config('localization.locales') as $code => $name)
            <a href="{{ request()->routeIs('marketing.*', '*.marketing.*') ? \App\Support\WebsiteLocale::route(request()->route()->getName(), locale: $code) : request()->fullUrlWithQuery(['lang' => $code]) }}" lang="{{ \App\Support\WebsiteLocale::languageTag($code) }}" hreflang="{{ \App\Support\WebsiteLocale::languageTag($code) }}" @if ($code === app()->getLocale()) aria-current="true" @endif>{{ $name }}</a>
        @endforeach
    </nav>
</details>
