{{--
    Product showcase con tabs. Mockups HTML/CSS con nombres de módulos REALES y
    valores neutros — sin cifras comerciales inventadas. Si el CMS aporta
    $hero->hero_image / features con imagen real, esas imágenes se prefieren en
    el hero; aquí la composición es esquemática por diseño.
--}}
@php
    $tabs = [
        ['id' => 'sales',     'label' => __('landing_prime.showcase_tab_sales'),     'desc' => __('landing_prime.showcase_sales_desc'),     'window' => __('landing_prime.showcase_window_sales'),
         'rows' => [__('landing_prime.value_pos'), __('landing_prime.calc_customers'), __('landing_prime.value_reports')]],
        ['id' => 'inventory', 'label' => __('landing_prime.showcase_tab_inventory'), 'desc' => __('landing_prime.showcase_inventory_desc'), 'window' => __('landing_prime.showcase_window_inventory'),
         'rows' => [__('landing_prime.multibranch_point1_title'), __('landing_prime.multibranch_point2_title'), __('landing_prime.value_inventory')]],
        ['id' => 'reports',   'label' => __('landing_prime.showcase_tab_reports'),   'desc' => __('landing_prime.showcase_reports_desc'),   'window' => __('landing_prime.showcase_window_reports'),
         'rows' => [__('landing_prime.reports_item1'), __('landing_prime.reports_item2'), __('landing_prime.reports_item4')]],
        ['id' => 'purchases', 'label' => __('landing_prime.showcase_tab_purchases'), 'desc' => __('landing_prime.showcase_purchases_desc'), 'window' => __('landing_prime.showcase_window_purchases'),
         'rows' => [__('landing_prime.value_purchases'), __('landing_prime.calc_suppliers'), __('landing_prime.reports_item3')]],
        ['id' => 'team',      'label' => __('landing_prime.showcase_tab_team'),      'desc' => __('landing_prime.showcase_team_desc'),      'window' => __('landing_prime.showcase_window_team'),
         'rows' => [__('landing_prime.multibranch_point3_title'), __('landing_prime.value_hr'), __('landing_prime.modules_team_title')]],
    ];
    $ph = __('landing_prime.showcase_placeholder');
@endphp

<section id="product" class="bg-white py-20 sm:py-28 px-5 sm:px-6">
    <div class="max-w-6xl mx-auto">
        <header class="max-w-2xl mb-10 lp-reveal">
            <h2 class="text-3xl sm:text-4xl font-bold tracking-tight text-slate-950 mb-3">{{ __('landing_prime.showcase_title') }}</h2>
            <p class="text-lg text-slate-600">{{ __('landing_prime.showcase_lead') }}</p>
        </header>

        <div class="lp-tabs__scroller overflow-x-auto -mx-5 px-5 mb-8 lp-reveal">
            <div id="lpShowcaseTabs" class="inline-flex gap-2 bg-slate-50 border border-slate-200 rounded-full p-1.5" role="tablist" aria-label="{{ __('landing_prime.showcase_title') }}">
                @foreach($tabs as $i => $tab)
                    <button type="button" class="lp-tab" role="tab" id="lp-tab-{{ $tab['id'] }}"
                            aria-controls="lp-panel-{{ $tab['id'] }}" aria-selected="{{ $i === 0 ? 'true' : 'false' }}"
                            tabindex="{{ $i === 0 ? '0' : '-1' }}">{{ $tab['label'] }}</button>
                @endforeach
            </div>
        </div>

        @foreach($tabs as $i => $tab)
            <div class="lp-tabpanel" id="lp-panel-{{ $tab['id'] }}" role="tabpanel" aria-labelledby="lp-tab-{{ $tab['id'] }}" @if($i !== 0) hidden @endif>
                <div class="grid lg:grid-cols-[320px_1fr] gap-8 lg:gap-14 items-center">
                    <div class="lp-reveal">
                        <p class="text-xl font-bold text-slate-950 mb-2">{{ $tab['label'] }}</p>
                        <p class="text-slate-600 leading-relaxed">{{ $tab['desc'] }}</p>
                    </div>
                    <div class="lp-window lp-reveal" data-delay="1">
                        <div class="lp-window__bar">
                            <span class="lp-window__dot"></span><span class="lp-window__dot"></span><span class="lp-window__dot"></span>
                            <span class="lp-window__title">{{ $tab['window'] }}</span>
                        </div>
                        <div class="lp-appui">
                            <div class="lp-appui__rail" aria-hidden="true">
                                <i class="bi bi-grid-1x2-fill is-active"></i>
                                <i class="bi bi-bag"></i>
                                <i class="bi bi-box-seam"></i>
                                <i class="bi bi-graph-up"></i>
                            </div>
                            <div class="lp-appui__main">
                                <div class="lp-appui__topbar">
                                    <i class="bi bi-window-stack text-indigo-500"></i><span>{{ $tab['label'] }}</span>
                                    <span class="lp-appui__pin" aria-hidden="true"></span>
                                </div>
                                <div class="grid grid-cols-[1fr_auto_auto] gap-x-4 text-[11px] font-semibold uppercase tracking-wide text-slate-400 pb-3 border-b border-slate-100">
                                    <span>{{ __('landing_prime.showcase_col_item') }}</span>
                                    <span>{{ __('landing_prime.showcase_col_detail') }}</span>
                                    <span>{{ __('landing_prime.showcase_col_status') }}</span>
                                </div>
                                @php $sp = [['ok','hero_mock_st_ok'],['info','hero_mock_st_sync'],['wait','hero_mock_st_low']]; @endphp
                                @foreach($tab['rows'] as $ri => $row)
                                    <div class="grid grid-cols-[1fr_auto_auto] gap-x-4 items-center py-3.5 border-b border-slate-50 text-sm last:border-0">
                                        <span class="font-medium text-slate-800">{{ $row }}</span>
                                        <span class="text-slate-300">{{ $ph }}</span>
                                        <span class="lp-pill lp-pill--{{ $sp[$ri % 3][0] }}"><i class="bi bi-circle-fill"></i>{{ __('landing_prime.' . $sp[$ri % 3][1]) }}</span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
</section>
