<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Services\LandingCmsService;
use App\Services\PlanRecommendationService;
use App\Support\LandingContact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoint público de la calculadora de precios de landing-prime.
 * Solo LEE planes (Plan::public()) vía PlanRecommendationService — no toca
 * billing, suscripciones ni la BD. El front lo invoca al mover los sliders; la
 * lógica de recomendación vive únicamente en el servicio.
 */
class PricingCalculatorController extends Controller
{
    /** Topes defensivos de entrada (alineados con los sliders del front). */
    private const CLAMP = [
        'max_users'      => 200,
        'max_warehouses' => 100,
        'max_customers'  => 100000,
        'max_suppliers'  => 100000,
        'max_products'   => 500000,
    ];

    public function recommend(Request $request, PlanRecommendationService $service, LandingCmsService $cms): JsonResponse
    {
        $cycle = $request->query('cycle') === 'yearly' ? 'yearly' : 'monthly';

        $input = [];
        foreach (PlanRecommendationService::DIMENSIONS as $key) {
            $value = (int) $request->query($key, '0');
            $input[$key] = max(0, min($value, self::CLAMP[$key]));
        }

        $footer = $cms->getSection('footer');
        $cta    = $cms->getSection('cta');

        $result = $service->recommend($input, $cycle, null, [
            'sales_url'         => LandingContact::salesUrl($footer, $cta),
            'register_base_url' => $this->safeRoute('central.register'),
        ]);

        return response()->json($result)
            ->header('Cache-Control', 'public, max-age=120');
    }

    private function safeRoute(string $name): string
    {
        try {
            return route($name);
        } catch (\Throwable) {
            return url('/register');
        }
    }
}
