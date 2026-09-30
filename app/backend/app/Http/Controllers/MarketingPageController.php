<?php

namespace App\Http\Controllers;

use App\Support\WebsiteLocale;
use Illuminate\View\View;

class MarketingPageController extends Controller
{
    public function home(): View
    {
        return view('marketing.how-to-use', [
            'pageTitle' => __('How To Use').' | '.config('marketing.product_name'),
            'metaDescription' => __('Open-source YouTube subtitles and language study. Run your own instance and bring your own API keys.'),
            'canonicalUrl' => WebsiteLocale::route('marketing.home'),
        ]);
    }
}
