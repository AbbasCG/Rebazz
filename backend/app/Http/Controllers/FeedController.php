<?php

namespace App\Http\Controllers;

use App\Services\ProductFeed;

/** Openbare productfeed (Google Merchant-formaat) voor Google Shopping, Meta, Pinterest en TikTok. */
class FeedController extends Controller
{
    public function google(ProductFeed $feed)
    {
        return response($feed->xml(), 200, ['Content-Type' => 'application/xml; charset=UTF-8', 'Cache-Control' => 'public, max-age=300']);
    }
}
