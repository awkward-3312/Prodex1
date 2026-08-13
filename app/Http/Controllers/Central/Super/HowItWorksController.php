<?php

namespace App\Http\Controllers\Central\Super;

use App\Http\Controllers\Controller;
use App\Models\Central\GeneralSetting;
use Illuminate\View\View;

class HowItWorksController extends Controller
{
    public function index(): View
    {
        $settings = GeneralSetting::instance();

        return view('central.super.how-it-works', [
            'hostingMode' => $settings->isSharedHosting() ? 'shared' : 'vps',
        ]);
    }
}
