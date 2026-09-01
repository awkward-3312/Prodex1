{{--
    Comparación de planes — SIEMPRE visible (no depende de recommendation_status).
    Fuente: $pricingCalculator['plans'] (PlanRecommendationService, withDetail).
    Cards NEUTRALES: sin "más popular", sin destacar un plan arbitrario. La
    calculadora sí realza discretamente el plan recomendado por plan_id (JS).
    Sólo se muestra información REAL: precio anual y ahorro sólo si son válidos,
    prueba sólo si es real, límites/features sólo si vienen del servicio.
--}}
@php
    $c = $pricingCalculator ?? null;
    $sym = $c['currency_symbol'] ?? ($currencySymbol ?? 'L');
    $calcPlans = $c['plans'] ?? [];
    $planCount = count($calcPlans);
    // Realce inicial (server-side) del plan que la calculadora ya recomienda.
    $recommendedId = ($c['recommendation_status'] ?? null) === 'ok' ? ($c['recommended']['id'] ?? null) : null;
    $lgCols = $planCount >= 4 ? 'lg:grid-cols-4' : ($planCount === 3 ? 'lg:grid-cols-3' : 'lg:grid-cols-2');

    $money = function ($amount) use ($sym) {
        $n = (float) $amount;
        return $sym . ' ' . (abs($n - floor($n)) < 0.005 ? number_format($n, 0) : number_format($n, 2));
    };

    // Singular cuando el tope es exactamente 1 ("1 Almacén", no "1 Almacenes").
    // Las etiquetas llegan en el payload del servicio (ES, estáticas del catálogo).
    $limitLabel = function (array $it) {
        if (($it['value'] ?? null) !== 1) {
            return $it['label'];
        }
        return [
            'Productos'   => 'Producto',
            'Usuarios'    => 'Usuario',
            'Almacenes'   => 'Almacén',
            'Clientes'    => 'Cliente',
            'Proveedores' => 'Proveedor',
            'Mensajes de WhatsApp al mes' => 'Mensaje de WhatsApp al mes',
        ][$it['label']] ?? $it['label'];
    };
@endphp

@if($planCount > 0)
<section id="plans" class="bg-white py-20 sm:py-28 px-5 sm:px-6" aria-labelledby="lpPlansHeading">
    <div class="max-w-6xl mx-auto">
        <header class="max-w-2xl mb-14 lp-reveal">
            <h2 id="lpPlansHeading" class="text-3xl sm:text-4xl font-bold tracking-tight text-slate-950 mb-3">{{ __('landing_prime.plans_section_title') }}</h2>
            <p class="text-lg text-slate-600">{{ __('landing_prime.plans_section_lead') }}</p>
        </header>

        <div id="lpPlans" class="grid gap-5 md:grid-cols-2 {{ $lgCols }}">
            @php
                // Personalidad de color por posición de precio (dinámico, sin hardcodear nombres):
                // 1º cyan · 2º azul · 3º índigo/violeta · 4º+ naranja.
                $planTone = fn ($n) => 'lp-plan--c' . min($n + 1, 4);
                $planIcon = ['bi-rocket-takeoff', 'bi-shop', 'bi-buildings', 'bi-diagram-3'];
            @endphp
            @foreach($calcPlans as $i => $p)
                @php
                    $includedAll = collect($p['included'] ?? []);
                    $allUnlimited = $includedAll->isNotEmpty() && $includedAll->every(fn ($it) => ! empty($it['unlimited']));
                    $included = $allUnlimited ? collect() : $includedAll->take(5);
                    $features = collect($p['features'] ?? [])->take(3);
                    $isPaid = empty($p['is_free']);
                    $hasDetail = $allUnlimited || $included->isNotEmpty() || $features->isNotEmpty();
                @endphp
                <article class="lp-plan {{ $planTone($i) }} lp-reveal {{ $recommendedId !== null && $recommendedId === $p['id'] ? 'is-recommended' : '' }}" data-plan-id="{{ $p['id'] }}" @if($i % 2) data-delay="1" @endif>
                    <span class="lp-plan__chip">{{ __('landing_prime.plans_recommended_chip') }}</span>

                    <span class="lp-plan__ic" aria-hidden="true"><i class="bi {{ $planIcon[min($i, 3)] }}"></i></span>
                    <p class="lp-plan__name">{{ $p['name'] }}</p>

                    <div class="lp-plan__price">
                        @if($p['is_free'])
                            <span class="lp-plan__amt lp-plan__amt--free">{{ __('landing_prime.calc_free') }}</span>
                        @else
                            <span class="lp-plan__amt lp-tnum">{{ $money($p['price_monthly']) }}</span>
                            <span class="lp-plan__per">{{ __('landing_prime.calc_per_month') }}</span>
                        @endif
                    </div>
                    <p class="lp-plan__yearly mt-1">
                        @if($p['yearly_available'])
                            {{ __('landing_prime.plans_yearly_line', ['amount' => $money($p['price_yearly']), 'percent' => $p['yearly_savings_percent']]) }}
                        @elseif($p['is_trial'] && $p['trial_days'] > 0)
                            {{ __('landing_prime.plans_trial_line', ['days' => $p['trial_days']]) }}
                        @endif
                    </p>

                    @if($hasDetail)
                        <div class="lp-plan__detail mt-5 pt-5">
                            <p class="lp-plan__kicker">{{ __('landing_prime.plans_includes') }}</p>
                            <ul class="lp-plan__list">
                                @if($allUnlimited)
                                    <li><i class="bi bi-check2" aria-hidden="true"></i><span>{{ __('landing_prime.plans_no_limits') }}</span></li>
                                @endif
                                @foreach($included as $it)
                                    <li><i class="bi bi-check2" aria-hidden="true"></i><span><strong>{{ $it['unlimited'] ? __('landing_prime.calc_unlimited') : $it['display'] }}</strong> {{ $limitLabel($it) }}</span></li>
                                @endforeach
                                @foreach($features as $lf)
                                    <li><i class="bi bi-check2" aria-hidden="true"></i><span>{{ $lf }}</span></li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <a class="lp-btn lp-btn--ghost w-full lp-plan__cta" href="{{ $p['register_url'] }}">
                        {{ $isPaid ? __('landing_prime.plans_cta_paid') : __('landing_prime.plans_cta') }}
                    </a>
                </article>
            @endforeach
        </div>
    </div>
</section>
@endif
