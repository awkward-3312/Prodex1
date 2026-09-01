{{--
    Calculadora de precios de landing-prime.
    Render server-side inicial desde $pricingCalculator (PlanRecommendationService).
    El front (landing-prime.js) recalcula vía /pricing/recommend — misma fuente.
    Clientes y proveedores son controles INDEPENDIENTES (nunca se suman).
--}}
@php
    $c = $pricingCalculator ?? null;
    $sym = $c['currency_symbol'] ?? ($currencySymbol ?? 'L');
    $status = $c['recommendation_status'] ?? 'insufficient_plan_data';
    $req = $c['request'] ?? ['max_users' => 1, 'max_warehouses' => 1, 'max_customers' => 100, 'max_suppliers' => 20, 'max_products' => 200];
    $planForSummary = $status === 'ok' ? ($c['recommended'] ?? null) : ($status === 'custom' ? ($c['starting_point'] ?? null) : null);
    $calcPlans = $c['plans'] ?? [];
    $salesHref = $c['sales_url'] ?? ($salesContactHref ?? '#contact-sales');
    $salesExternal = \App\Support\LandingContact::isExternal($salesHref);

    $maxSave = 0;
    foreach ($calcPlans as $p) {
        if (! empty($p['yearly_available']) && ($p['yearly_savings_percent'] ?? 0) > $maxSave) {
            $maxSave = (int) $p['yearly_savings_percent'];
        }
    }

    $fmtMoney = function ($amount) use ($sym) {
        $n = (float) $amount;
        $body = abs($n - floor($n)) < 0.005 ? number_format($n, 0) : number_format($n, 2);
        return $sym . ' ' . $body;
    };

    $fields = [
        ['dim' => 'max_users',      'label' => __('landing_prime.calc_users'),      'hint' => __('landing_prime.calc_users_hint'),      'min' => 1, 'max' => 100,    'step' => 1],
        ['dim' => 'max_warehouses', 'label' => __('landing_prime.calc_warehouses'), 'hint' => __('landing_prime.calc_warehouses_hint'), 'min' => 1, 'max' => 50,     'step' => 1],
        ['dim' => 'max_customers',  'label' => __('landing_prime.calc_customers'),  'hint' => null, 'min' => 0, 'max' => 50000,  'step' => 250, 'group' => __('landing_prime.calc_contacts_group')],
        ['dim' => 'max_suppliers',  'label' => __('landing_prime.calc_suppliers'),  'hint' => null, 'min' => 0, 'max' => 10000,  'step' => 50],
        ['dim' => 'max_products',   'label' => __('landing_prime.calc_products'),   'hint' => __('landing_prime.calc_products_hint'), 'min' => 0, 'max' => 100000, 'step' => 500],
    ];

    $billPeriod = fn ($plan) => ($plan['billed_period'] ?? 'month') === 'year' ? __('landing_prime.calc_per_year') : __('landing_prime.calc_per_month');
    $billNote = fn ($plan) => ($plan['billed_period'] ?? 'month') === 'year' ? __('landing_prime.calc_billed_yearly') : __('landing_prime.calc_billed_monthly');
@endphp

<section id="pricing" class="lp-soft lp-aurora py-20 sm:py-28 px-5 sm:px-6">
    <div class="max-w-6xl mx-auto">
        <header class="max-w-2xl mx-auto text-center mb-12 lp-reveal">
            <span class="lp-mark lp-mark--center mb-5"></span>
            <h2 class="text-3xl sm:text-4xl font-bold tracking-[-0.02em] text-slate-950 mb-3">{{ __('landing_prime.calc_title') }}</h2>
            <p class="text-lg text-slate-600">{{ __('landing_prime.calc_lead') }}</p>
        </header>

        <div id="lpCalc" class="lp-calc grid lg:grid-cols-[1fr_380px] gap-6 lg:gap-10"
             data-status="{{ $status }}"
             data-endpoint="{{ route('central.pricing.recommend') }}"
             data-currency="{{ $sym }}"
             data-i18n-unlimited="{{ __('landing_prime.calc_unlimited') }}"
             data-i18n-per-month="{{ __('landing_prime.calc_per_month') }}"
             data-i18n-per-year="{{ __('landing_prime.calc_per_year') }}"
             data-i18n-billed-monthly="{{ __('landing_prime.calc_billed_monthly') }}"
             data-i18n-billed-yearly="{{ __('landing_prime.calc_billed_yearly') }}"
             data-i18n-trial="{{ __('landing_prime.calc_trial_note', ['days' => ':days']) }}"
             data-i18n-all-modules="{{ __('landing_prime.calc_all_modules') }}"
             data-i18n-custom-start="{{ __('landing_prime.calc_custom_start', ['plan' => ':plan']) }}"
             data-i18n-free="{{ __('landing_prime.calc_free') }}"
             data-i18n-live-ok="{{ __('landing_prime.calc_live_ok', ['plan' => ':plan', 'amount' => ':amount']) }}"
             data-i18n-live-custom="{{ __('landing_prime.calc_live_custom') }}"
             data-i18n-live-nodata="{{ __('landing_prime.calc_live_nodata') }}">

            {{-- ── TU NEGOCIO — panel de configuración ─────────────────── --}}
            <div class="lp-reveal">
              <p class="lp-calc__col-label">{{ __('landing_prime.calc_col_business') }}</p>
              <div class="lp-card rounded-2xl border border-slate-200 bg-white p-5 sm:p-7">
                {{-- Ciclo --}}
                <div class="flex items-center justify-between flex-wrap gap-3 mb-7">
                    <span class="text-sm font-semibold text-slate-700">{{ __('landing_prime.calc_cycle_label') }}</span>
                    <div class="lp-seg" role="group" aria-label="{{ __('landing_prime.calc_cycle_label') }}">
                        <button type="button" class="lp-seg__btn" data-cycle="monthly" aria-pressed="true">{{ __('landing_prime.calc_cycle_monthly') }}</button>
                        <button type="button" class="lp-seg__btn" data-cycle="yearly" aria-pressed="false">
                            {{ __('landing_prime.calc_cycle_yearly') }}
                            <span class="lp-seg__save" data-calc-save data-tmpl="{{ __('landing_prime.calc_cycle_save', ['percent' => ':percent']) }}" @if($maxSave <= 0) hidden @endif>{{ __('landing_prime.calc_cycle_save', ['percent' => $maxSave]) }}</span>
                        </button>
                    </div>
                </div>

                <p class="text-sm font-semibold text-slate-700 mb-5">{{ __('landing_prime.calc_config_label') }}</p>

                <div class="space-y-7">
                    @foreach($fields as $f)
                        @if(!empty($f['group']))
                            <p class="pt-3 text-sm font-semibold text-slate-700">{{ $f['group'] }}</p>
                        @endif
                        <div class="lp-field {{ in_array($f['dim'], ['max_customers', 'max_suppliers'], true) ? 'ml-3' : '' }}" data-dim="{{ $f['dim'] }}">
                            <div class="flex items-center justify-between gap-4 mb-1">
                                <label class="text-sm text-slate-700" for="lp-range-{{ $f['dim'] }}">
                                    {{ $f['label'] }}
                                    @if($f['hint'])<span class="block text-xs text-slate-400">{{ $f['hint'] }}</span>@endif
                                </label>
                                <span class="lp-step">
                                    <button type="button" class="lp-step__btn" data-step-dir="down" aria-label="{{ __('landing_prime.calc_decrease') }} · {{ $f['label'] }}">&minus;</button>
                                    <input class="lp-step__val lp-tnum" type="text" inputmode="numeric" pattern="[0-9]*"
                                           value="{{ (int) ($req[$f['dim']] ?? $f['min']) }}"
                                           aria-label="{{ $f['label'] }}">
                                    <button type="button" class="lp-step__btn" data-step-dir="up" aria-label="{{ __('landing_prime.calc_increase') }} · {{ $f['label'] }}">+</button>
                                </span>
                            </div>
                            <input id="lp-range-{{ $f['dim'] }}" type="range" class="lp-range lp-range--track"
                                   min="{{ $f['min'] }}" max="{{ $f['max'] }}" step="{{ $f['step'] }}"
                                   value="{{ (int) ($req[$f['dim']] ?? $f['min']) }}"
                                   aria-label="{{ $f['label'] }}">
                        </div>
                    @endforeach
                </div>
              </div>
            </div>

            {{-- ── TU PRODEX — resumen del plan recomendado ────────────── --}}
            <aside class="lp-calc__summary lp-calc__summary--sticky lp-reveal" data-delay="1">
                <p class="lp-calc__col-label">{{ __('landing_prime.calc_col_prodex') }}</p>
                <p class="sr-only" role="status" aria-live="polite" data-calc-live></p>
                <div class="lp-calc__card lp-card lp-elevate rounded-2xl border border-slate-200 bg-white p-6">

                    {{-- Estado OK --}}
                    <div class="lp-calc__state lp-calc__state--ok">
                        <p class="text-center"><span class="lp-calc__badge" data-calc-badge>{{ __('landing_prime.calc_recommended') }}</span></p>
                        <div class="lp-calc__figure text-center mt-3">
                            <h3 class="text-2xl font-bold text-slate-950" data-calc-name>{{ $planForSummary['name'] ?? '—' }}</h3>
                            <p class="mt-2">
                                <span class="text-4xl font-extrabold tracking-tight text-slate-950 lp-tnum" data-calc-amount>{{ $planForSummary ? ($planForSummary['is_free'] ? __('landing_prime.calc_free') : $fmtMoney($planForSummary['billed_amount'])) : '—' }}</span>
                                <span class="text-slate-500 font-medium" data-calc-period>{{ $planForSummary && !$planForSummary['is_free'] ? $billPeriod($planForSummary) : '' }}</span>
                            </p>
                            <p class="text-xs text-slate-400 mt-1" data-calc-billnote>{{ $planForSummary && !$planForSummary['is_free'] ? $billNote($planForSummary) : '' }}</p>
                        </div>

                        <div class="mt-5 pt-5 border-t border-slate-100">
                            <p class="text-xs font-bold uppercase tracking-wide text-slate-400 mb-3">{{ __('landing_prime.calc_includes') }}</p>
                            <ul class="space-y-2 text-sm text-slate-700" data-calc-included>
                                @foreach(($planForSummary['included'] ?? []) as $it)
                                    <li><strong>{{ $it['unlimited'] ? __('landing_prime.calc_unlimited') : $it['display'] }}</strong> {{ $it['label'] }}</li>
                                @endforeach
                            </ul>
                            @php $summaryFeatures = $planForSummary['features'] ?? []; @endphp
                            <ul class="mt-3 space-y-2 text-sm text-slate-600" data-calc-features>
                                @forelse($summaryFeatures as $lf)
                                    <li>{{ $lf }}</li>
                                @empty
                                    <li>{{ __('landing_prime.calc_all_modules') }}</li>
                                @endforelse
                            </ul>
                        </div>

                        <a class="lp-btn lp-btn--primary w-full mt-5" data-calc-cta href="{{ $planForSummary['register_url'] ?? ($c['register_base_url'] ?? route('central.register')) }}">
                            {{ __('landing_prime.calc_cta') }}
                        </a>
                        <p class="text-center text-xs text-slate-400 mt-2" data-calc-trialnote @if(! ($planForSummary['is_trial'] ?? false) || ($planForSummary['trial_days'] ?? 0) < 1) hidden @endif>
                            {{ __('landing_prime.calc_trial_note', ['days' => (int) ($planForSummary['trial_days'] ?? 0)]) }}
                        </p>

                        <p class="text-center text-sm text-slate-500 mt-4 pt-4 border-t border-slate-100">
                            {{ __('landing_prime.calc_custom_q') }}
                            <a class="font-semibold text-indigo-600" data-calc-sales href="{{ $salesHref }}" @if($salesExternal) target="_blank" rel="noopener noreferrer" @endif>{{ __('landing_prime.calc_custom_link') }}</a>
                        </p>
                    </div>

                    {{-- Estado custom (ningún plan cumple) --}}
                    <div class="lp-calc__state lp-calc__state--custom text-center justify-center">
                        <p><span class="lp-calc__badge">{{ __('landing_prime.calc_custom_title') }}</span></p>
                        <p class="mt-4 text-sm text-slate-600">{{ __('landing_prime.calc_custom_lead') }}</p>
                        <p class="mt-3 text-xs text-slate-400" data-calc-startplan>
                            {{ $status === 'custom' && $planForSummary ? __('landing_prime.calc_custom_start', ['plan' => $planForSummary['name']]) : '' }}
                        </p>
                        <a class="lp-btn lp-btn--primary w-full mt-5" data-calc-sales href="{{ $salesHref }}" @if($salesExternal) target="_blank" rel="noopener noreferrer" @endif>
                            {{ __('landing_prime.calc_custom_cta') }}
                        </a>
                    </div>

                    {{-- Estado insufficient_plan_data --}}
                    <div class="lp-calc__state lp-calc__state--nodata text-center justify-center">
                        <h3 class="text-lg font-bold text-slate-950">{{ __('landing_prime.calc_nodata_title') }}</h3>
                        <p class="mt-3 text-sm text-slate-600">{{ __('landing_prime.calc_nodata_lead') }}</p>
                        <a class="lp-btn lp-btn--primary w-full mt-5" data-calc-sales href="{{ $salesHref }}" @if($salesExternal) target="_blank" rel="noopener noreferrer" @endif>
                            {{ __('landing_prime.calc_nodata_cta') }}
                        </a>
                        {{-- La comparación de planes vive en su propia sección, siempre visible. --}}
                        <a class="lp-btn lp-btn--ghost w-full mt-2" href="#plans">{{ __('landing_prime.calc_see_plans') }}</a>
                    </div>
                </div>
            </aside>
        </div>
    </div>
</section>
