@php
  use App\Models\StoreSetting;
  $s = $s ?? StoreSetting::first();
  $accent = $s->primary_color ?? '#1a7f6b';
  $accentDark = $s->secondary_color ?? '#0f5a4c';
  $storeName = $s->store_name ?? 'Estate';
  $logo = $s->logo_path ? global_asset($s->logo_path) : null;
  $currency = $s->currency_code ?? '$';
  $locale = app()->getLocale();
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ in_array($locale, ['ar']) ? 'rtl' : 'ltr' }}">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>@yield('title', $s->seo_meta_title ?? $storeName)</title>
  <meta name="description" content="@yield('meta_description', $s->seo_meta_description ?? '')">
  @if($s && $s->favicon_path)
    <link rel="icon" href="{{ global_asset($s->favicon_path) }}">
  @endif
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    :root{
      --re-accent: {{ $accent }};
      --re-accent-dark: {{ $accentDark }};
      --re-ink:#15212b; --re-muted:#6b7a87; --re-line:#e7ecef;
      --re-bg:#f6f8f9; --re-card:#ffffff;
    }
    *{box-sizing:border-box}
    body{margin:0;font-family:'Inter',system-ui,Segoe UI,Roboto,Arial,sans-serif;color:var(--re-ink);background:var(--re-bg);line-height:1.5}
    a{color:inherit;text-decoration:none}
    img{max-width:100%;display:block}
    .re-container{max-width:1180px;margin:0 auto;padding:0 18px}
    .re-btn{display:inline-flex;align-items:center;gap:8px;border:0;cursor:pointer;font-weight:600;border-radius:10px;padding:11px 18px;font-size:14px;transition:.18s;text-align:center}
    .re-btn-primary{background:var(--re-accent);color:#fff}
    .re-btn-primary:hover{background:var(--re-accent-dark)}
    .re-btn-ghost{background:#fff;color:var(--re-ink);border:1px solid var(--re-line)}
    .re-btn-ghost:hover{border-color:var(--re-accent);color:var(--re-accent)}
    .re-btn-wa{background:#25d366;color:#fff}
    .re-btn-block{width:100%;justify-content:center}
    .re-badge{display:inline-block;font-size:11px;font-weight:700;letter-spacing:.3px;text-transform:uppercase;padding:4px 9px;border-radius:6px}
    .re-badge-sale{background:rgba(26,127,107,.12);color:var(--re-accent)}
    .re-badge-rent{background:rgba(37,99,235,.12);color:#2563eb}
    .re-badge-sold{background:rgba(220,38,38,.12);color:#dc2626}
    .re-badge-rented{background:rgba(217,119,6,.12);color:#d97706}
    .re-badge-featured{background:#15212b;color:#fff}

    /* Header */
    .re-header{position:sticky;top:0;z-index:40;background:#fff;border-bottom:1px solid var(--re-line)}
    .re-nav{display:flex;align-items:center;justify-content:space-between;height:68px;gap:16px}
    .re-brand{display:flex;align-items:center;gap:10px;font-weight:800;font-size:20px}
    .re-brand .dot{width:30px;height:30px;border-radius:8px;background:var(--re-accent);display:inline-flex;align-items:center;justify-content:center;color:#fff;font-size:16px}
    .re-menu{display:flex;align-items:center;gap:24px;font-weight:500;font-size:14px}
    .re-menu a:hover{color:var(--re-accent)}
    .re-actions{display:flex;align-items:center;gap:10px}
    .re-burger{display:none;background:none;border:1px solid var(--re-line);border-radius:8px;padding:8px 10px;cursor:pointer}
    .re-mobile{display:none;border-top:1px solid var(--re-line);padding:10px 0}
    .re-mobile a{display:block;padding:10px 18px;font-weight:500}

    /* Footer */
    .re-footer{background:#15212b;color:#c7d2da;margin-top:60px;padding:48px 0 24px}
    .re-footer h4{color:#fff;font-size:15px;margin:0 0 14px}
    .re-footer a{display:block;color:#aab8c2;padding:4px 0;font-size:14px}
    .re-footer a:hover{color:#fff}
    .re-foot-grid{display:grid;grid-template-columns:2fr 1fr 1fr;gap:32px}
    .re-foot-bottom{border-top:1px solid rgba(255,255,255,.1);margin-top:32px;padding-top:18px;font-size:13px;color:#8a98a3;display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px}

    /* Property card */
    .re-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:22px}
    .re-grid.cols-2{grid-template-columns:repeat(2,1fr)}
    .re-grid.cols-4{grid-template-columns:repeat(4,1fr)}
    .re-card{background:var(--re-card);border:1px solid var(--re-line);border-radius:14px;overflow:hidden;transition:.18s;display:flex;flex-direction:column}
    .re-card:hover{box-shadow:0 12px 30px rgba(21,33,43,.10);transform:translateY(-3px)}
    .re-card-media{position:relative;aspect-ratio:4/3;background:#e9eef0;overflow:hidden}
    .re-card-media img{width:100%;height:100%;object-fit:cover}
    .re-card-tags{position:absolute;top:10px;left:10px;display:flex;gap:6px}
    .re-card-price{position:absolute;bottom:10px;left:10px;background:rgba(21,33,43,.86);color:#fff;font-weight:700;padding:6px 11px;border-radius:8px;font-size:14px}
    .re-card-body{padding:14px 16px 16px;display:flex;flex-direction:column;gap:8px;flex:1}
    .re-card-title{font-weight:700;font-size:16px;margin:0;line-height:1.35}
    .re-card-loc{color:var(--re-muted);font-size:13px;display:flex;align-items:center;gap:5px}
    .re-specs{display:flex;gap:14px;flex-wrap:wrap;border-top:1px solid var(--re-line);margin-top:auto;padding-top:12px;color:var(--re-muted);font-size:13px}
    .re-specs b{color:var(--re-ink);font-weight:600}

    .re-list-row{display:grid;grid-template-columns:300px 1fr;gap:0}
    @media(max-width:860px){.re-list-row{grid-template-columns:1fr}}

    /* Sections */
    .re-section{padding:48px 0}
    .re-section-head{display:flex;align-items:end;justify-content:space-between;margin-bottom:24px;gap:12px;flex-wrap:wrap}
    .re-kicker{color:var(--re-accent);font-weight:700;font-size:13px;letter-spacing:.5px;text-transform:uppercase}
    .re-title{font-size:26px;font-weight:800;margin:4px 0 0}

    .re-cat-grid{display:grid;grid-template-columns:repeat(6,1fr);gap:14px}
    .re-cat{background:#fff;border:1px solid var(--re-line);border-radius:12px;padding:18px 12px;text-align:center;transition:.18s}
    .re-cat:hover{border-color:var(--re-accent);transform:translateY(-2px)}
    .re-cat .ic{font-size:24px}
    .re-cat .nm{font-weight:600;font-size:14px;margin-top:6px}
    .re-cat .ct{color:var(--re-muted);font-size:12px}

    .re-loc-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}
    .re-loc{position:relative;border-radius:14px;overflow:hidden;background:var(--re-accent);color:#fff;padding:26px 20px;min-height:120px;display:flex;flex-direction:column;justify-content:end}
    .re-loc .city{font-weight:700;font-size:18px}
    .re-loc .ct{opacity:.85;font-size:13px}

    /* Forms */
    .re-field{margin-bottom:14px}
    .re-label{display:block;font-size:13px;font-weight:600;margin-bottom:6px;color:var(--re-ink)}
    .re-input,.re-select,.re-textarea{width:100%;border:1px solid var(--re-line);border-radius:10px;padding:11px 12px;font-size:14px;font-family:inherit;background:#fff;color:var(--re-ink)}
    .re-input:focus,.re-select:focus,.re-textarea:focus{outline:none;border-color:var(--re-accent);box-shadow:0 0 0 3px rgba(26,127,107,.12)}
    .re-card-pad{background:#fff;border:1px solid var(--re-line);border-radius:14px;padding:20px}
    .re-alert{padding:11px 14px;border-radius:10px;font-size:14px;margin-bottom:12px}
    .re-alert-ok{background:rgba(26,127,107,.1);color:var(--re-accent-dark)}
    .re-alert-err{background:rgba(220,38,38,.1);color:#b91c1c}
    .re-hidden{display:none}

    /* Pager */
    .re-pager{display:flex;gap:6px;justify-content:center;flex-wrap:wrap}
    .re-page{min-width:40px;height:40px;display:inline-flex;align-items:center;justify-content:center;border:1px solid var(--re-line);border-radius:10px;background:#fff;font-weight:600;font-size:14px;padding:0 10px}
    .re-page:hover{border-color:var(--re-accent);color:var(--re-accent)}
    .re-page.active{background:var(--re-accent);color:#fff;border-color:var(--re-accent)}
    .re-page.disabled{opacity:.4;pointer-events:none}

    @media(max-width:980px){
      .re-grid,.re-grid.cols-4{grid-template-columns:repeat(2,1fr)}
      .re-cat-grid{grid-template-columns:repeat(3,1fr)}
      .re-loc-grid{grid-template-columns:repeat(2,1fr)}
      .re-foot-grid{grid-template-columns:1fr 1fr}
    }
    @media(max-width:680px){
      .re-menu{display:none}
      .re-burger{display:inline-flex}
      .re-grid,.re-grid.cols-2,.re-grid.cols-4{grid-template-columns:1fr}
      .re-cat-grid{grid-template-columns:repeat(2,1fr)}
      .re-loc-grid{grid-template-columns:1fr}
      .re-foot-grid{grid-template-columns:1fr}
    }
  </style>
  @stack('head')
</head>
<body>
  <header class="re-header">
    <div class="re-container">
      <nav class="re-nav">
        <a class="re-brand" href="{{ route('store.index') }}">
          @if($logo)
            <img src="{{ $logo }}" alt="{{ $storeName }}" style="height:34px;width:auto">
          @else
            <span class="dot">🏠</span>
          @endif
          <span>{{ $storeName }}</span>
        </a>
        <div class="re-menu">
          <a href="{{ route('store.index') }}">{{ __('messages.Home') }}</a>
          <a href="{{ route('store.realestate.listings') }}">{{ __('messages.Properties') }}</a>
          <a href="{{ route('store.realestate.listings', ['purpose' => 'sale']) }}">{{ __('messages.ForSale') }}</a>
          <a href="{{ route('store.realestate.listings', ['purpose' => 'rent']) }}">{{ __('messages.ForRent') }}</a>
          <a href="{{ route('store.contact') }}">{{ __('messages.ContactUs') }}</a>
        </div>
        <div class="re-actions">
          <a class="re-btn re-btn-primary" href="{{ route('store.realestate.listings') }}">{{ __('messages.BrowseProperties') }}</a>
          <button class="re-burger" onclick="document.getElementById('reMobile').style.display = (document.getElementById('reMobile').style.display==='block'?'none':'block')" aria-label="Menu">☰</button>
        </div>
      </nav>
    </div>
    <div class="re-mobile" id="reMobile">
      <a href="{{ route('store.index') }}">{{ __('messages.Home') }}</a>
      <a href="{{ route('store.realestate.listings') }}">{{ __('messages.Properties') }}</a>
      <a href="{{ route('store.realestate.listings', ['purpose' => 'sale']) }}">{{ __('messages.ForSale') }}</a>
      <a href="{{ route('store.realestate.listings', ['purpose' => 'rent']) }}">{{ __('messages.ForRent') }}</a>
      <a href="{{ route('store.contact') }}">{{ __('messages.ContactUs') }}</a>
    </div>
  </header>

  <main>
    @yield('content')
  </main>

  <footer class="re-footer">
    <div class="re-container">
      <div class="re-foot-grid">
        <div>
          <h4>{{ $storeName }}</h4>
          <p style="color:#aab8c2;font-size:14px;max-width:360px;margin:0">
            {{ $s->footer_text ?? __('messages.RealEstateFooterTagline') }}
          </p>
          <div style="margin-top:14px;color:#aab8c2;font-size:14px">
            @if($s && $s->contact_phone)<div>📞 {{ $s->contact_phone }}</div>@endif
            @if($s && $s->contact_email)<div>✉️ {{ $s->contact_email }}</div>@endif
            @if($s && $s->contact_address)<div>📍 {{ $s->contact_address }}</div>@endif
          </div>
        </div>
        <div>
          <h4>{{ __('messages.Explore') }}</h4>
          <a href="{{ route('store.realestate.listings') }}">{{ __('messages.AllProperties') }}</a>
          <a href="{{ route('store.realestate.listings', ['purpose' => 'sale']) }}">{{ __('messages.ForSale') }}</a>
          <a href="{{ route('store.realestate.listings', ['purpose' => 'rent']) }}">{{ __('messages.ForRent') }}</a>
        </div>
        <div>
          <h4>{{ __('messages.Company') }}</h4>
          <a href="{{ route('store.contact') }}">{{ __('messages.ContactUs') }}</a>
        </div>
      </div>
      <div class="re-foot-bottom">
        <span>© {{ date('Y') }} {{ $storeName }}. {{ __('messages.AllRightsReserved') }}</span>
        <span>{{ __('messages.PoweredByStorefront') }}</span>
      </div>
    </div>
  </footer>
  @stack('scripts')
</body>
</html>
