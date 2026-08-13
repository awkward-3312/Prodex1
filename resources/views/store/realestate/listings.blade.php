@extends('store.realestate.layout')

@section('title', __('messages.Properties') . ' — ' . ($s->store_name ?? ''))

@section('content')
@php $f = $filters; @endphp

<section style="background:linear-gradient(120deg,var(--re-accent-dark),var(--re-accent));color:#fff;padding:38px 0">
  <div class="re-container">
    <h1 style="margin:0;font-size:30px;font-weight:800">{{ __('messages.PropertyListings') }}</h1>
    <p style="margin:6px 0 0;opacity:.9">{{ $properties->total() }} {{ __('messages.PropertiesFound') }}</p>
  </div>
</section>

<section class="re-section">
  <div class="re-container" style="display:grid;grid-template-columns:300px 1fr;gap:26px;align-items:start">

    {{-- FILTERS --}}
    <aside class="re-card-pad" style="position:sticky;top:84px">
      <h3 style="margin:0 0 14px;font-size:17px">{{ __('messages.SearchFilters') }}</h3>
      <form method="GET" action="{{ route('store.realestate.listings') }}">
        <input type="hidden" name="view" value="{{ $f['view'] }}">
        <div class="re-field">
          <label class="re-label">{{ __('messages.Keyword') }}</label>
          <input class="re-input" type="text" name="q" value="{{ $f['q'] }}" placeholder="{{ __('messages.PropertyTitle') }}">
        </div>
        <div class="re-field">
          <label class="re-label">{{ __('messages.PropertyType') }}</label>
          <select class="re-select" name="category">
            <option value="">{{ __('messages.AllTypes') }}</option>
            @foreach($categories as $c)
              <option value="{{ $c->slug }}" @selected($f['category'] == $c->slug || $f['category'] == $c->id)>{{ $c->name }}</option>
            @endforeach
          </select>
        </div>
        <div class="re-field">
          <label class="re-label">{{ __('messages.Purpose') }}</label>
          <select class="re-select" name="purpose">
            <option value="">{{ __('messages.BuyOrRent') }}</option>
            <option value="sale" @selected($f['purpose']==='sale')>{{ __('messages.ForSale') }}</option>
            <option value="rent" @selected($f['purpose']==='rent')>{{ __('messages.ForRent') }}</option>
          </select>
        </div>
        <div class="re-field">
          <label class="re-label">{{ __('messages.Location') }}</label>
          <input class="re-input" type="text" name="location" value="{{ $f['location'] }}" placeholder="{{ __('messages.CityOrRegion') }}">
        </div>
        <div class="re-field" style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
          <div>
            <label class="re-label">{{ __('messages.MinPrice') }}</label>
            <input class="re-input" type="number" name="min_price" value="{{ $f['minPrice'] }}" min="0">
          </div>
          <div>
            <label class="re-label">{{ __('messages.MaxPrice') }}</label>
            <input class="re-input" type="number" name="max_price" value="{{ $f['maxPrice'] }}" min="0">
          </div>
        </div>
        <div class="re-field" style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
          <div>
            <label class="re-label">{{ __('messages.Bedrooms') }}</label>
            <select class="re-select" name="bedrooms">
              <option value="">{{ __('messages.Any') }}</option>
              @foreach([1,2,3,4,5] as $n)
                <option value="{{ $n }}" @selected($f['bedrooms']==$n)>{{ $n }}+</option>
              @endforeach
            </select>
          </div>
          <div>
            <label class="re-label">{{ __('messages.Bathrooms') }}</label>
            <select class="re-select" name="bathrooms">
              <option value="">{{ __('messages.Any') }}</option>
              @foreach([1,2,3,4] as $n)
                <option value="{{ $n }}" @selected($f['bathrooms']==$n)>{{ $n }}+</option>
              @endforeach
            </select>
          </div>
        </div>
        <div class="re-field">
          <label class="re-label">{{ __('messages.MinArea') }}</label>
          <input class="re-input" type="number" name="min_area" value="{{ $f['minArea'] }}" min="0">
        </div>
        <button class="re-btn re-btn-primary re-btn-block" type="submit">{{ __('messages.ApplyFilters') }}</button>
        <a class="re-btn re-btn-ghost re-btn-block" style="margin-top:8px" href="{{ route('store.realestate.listings') }}">{{ __('messages.Reset') }}</a>
      </form>
    </aside>

    {{-- RESULTS --}}
    <div>
      <div class="re-card-pad" style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:18px;padding:12px 16px;flex-wrap:wrap">
        <div style="display:flex;gap:6px">
          @php
            $gridQs = array_merge(request()->query(), ['view'=>'grid']);
            $listQs = array_merge(request()->query(), ['view'=>'list']);
          @endphp
          <a class="re-btn {{ $f['view']==='grid' ? 're-btn-primary':'re-btn-ghost' }}" href="?{{ http_build_query($gridQs) }}">▦ {{ __('messages.Grid') }}</a>
          <a class="re-btn {{ $f['view']==='list' ? 're-btn-primary':'re-btn-ghost' }}" href="?{{ http_build_query($listQs) }}">☰ {{ __('messages.List') }}</a>
        </div>
        <form method="GET" action="{{ route('store.realestate.listings') }}" style="display:flex;align-items:center;gap:8px">
          @foreach(['q','category','purpose','location','min_price','max_price','bedrooms','bathrooms','min_area','view'] as $hk)
            @if(request()->filled($hk))<input type="hidden" name="{{ $hk }}" value="{{ request($hk) }}">@endif
          @endforeach
          <label class="re-label" style="margin:0">{{ __('messages.SortBy') }}</label>
          <select class="re-select" name="sort" onchange="this.form.submit()" style="width:auto">
            <option value="latest" @selected($f['sort']==='latest')>{{ __('messages.Newest') }}</option>
            <option value="price_asc" @selected($f['sort']==='price_asc')>{{ __('messages.PriceLowToHigh') }}</option>
            <option value="price_desc" @selected($f['sort']==='price_desc')>{{ __('messages.PriceHighToLow') }}</option>
          </select>
        </form>
      </div>

      @if($properties->count())
        @if($f['view']==='list')
          <div style="display:flex;flex-direction:column;gap:18px">
            @foreach($properties as $property)
              @php
                $img = $property->featured_image ? global_asset($property->featured_image) : (is_array($property->gallery) && count($property->gallery) ? global_asset($property->gallery[0]) : null);
                $loc = collect([$property->city,$property->region])->filter()->implode(', ');
              @endphp
              <article class="re-card re-list-row">
                <a class="re-card-media" href="{{ route('store.realestate.show', $property->slug) }}" style="aspect-ratio:auto;min-height:200px">
                  @if($img)<img src="{{ $img }}" alt="{{ $property->title }}" loading="lazy">@else<div style="height:100%;display:flex;align-items:center;justify-content:center;font-size:34px;color:#9aa7af">🏡</div>@endif
                </a>
                <div class="re-card-body" style="padding:18px 20px">
                  <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap">
                    <span class="re-kicker" style="font-size:11px">{{ $property->category->name ?? '' }}</span>
                    <span style="font-weight:800;color:var(--re-accent);font-size:18px">{{ $currency }}{{ number_format((float)$property->price) }}@if($property->purpose==='rent')<span style="font-weight:500;color:var(--re-muted)">/{{ __('messages.Month') }}</span>@endif</span>
                  </div>
                  <h3 class="re-card-title" style="font-size:18px"><a href="{{ route('store.realestate.show', $property->slug) }}">{{ $property->title }}</a></h3>
                  @if($loc)<div class="re-card-loc">📍 {{ $loc }}</div>@endif
                  <p style="color:var(--re-muted);font-size:14px;margin:0">{{ \Illuminate\Support\Str::limit(strip_tags($property->description), 150) }}</p>
                  <div class="re-specs">
                    @if(!is_null($property->bedrooms))<span>🛏 <b>{{ $property->bedrooms }}</b> {{ __('messages.Beds') }}</span>@endif
                    @if(!is_null($property->bathrooms))<span>🛁 <b>{{ $property->bathrooms }}</b> {{ __('messages.Baths') }}</span>@endif
                    @if(!is_null($property->area))<span>📐 <b>{{ rtrim(rtrim(number_format((float)$property->area,2),'0'),'.') }}</b> {{ $property->area_unit }}</span>@endif
                  </div>
                </div>
              </article>
            @endforeach
          </div>
        @else
          <div class="re-grid cols-2">
            @foreach($properties as $property)
              @include('store.realestate.partials.card', ['property' => $property, 'currency' => $currency])
            @endforeach
          </div>
        @endif

        @if($properties->hasPages())
          <nav class="re-pager" style="margin-top:28px">
            @if($properties->onFirstPage())
              <span class="re-page disabled">‹</span>
            @else
              <a class="re-page" href="{{ $properties->previousPageUrl() }}">‹</a>
            @endif
            @foreach($properties->getUrlRange(max(1,$properties->currentPage()-2), min($properties->lastPage(),$properties->currentPage()+2)) as $page => $url)
              <a class="re-page {{ $page == $properties->currentPage() ? 'active' : '' }}" href="{{ $url }}">{{ $page }}</a>
            @endforeach
            @if($properties->hasMorePages())
              <a class="re-page" href="{{ $properties->nextPageUrl() }}">›</a>
            @else
              <span class="re-page disabled">›</span>
            @endif
          </nav>
        @endif
      @else
        <div class="re-card-pad" style="text-align:center;padding:60px 20px;color:var(--re-muted)">
          <div style="font-size:42px">🔎</div>
          <h3 style="margin:12px 0 4px;color:var(--re-ink)">{{ __('messages.NoPropertiesFound') }}</h3>
          <p style="margin:0">{{ __('messages.TryAdjustingFilters') }}</p>
        </div>
      @endif
    </div>
  </div>
</section>
@endsection
