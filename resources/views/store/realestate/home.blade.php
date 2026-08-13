@extends('store.realestate.layout')

@section('title', ($s->seo_meta_title ?? $s->store_name ?? 'Real Estate'))

@section('content')
@php
  $hero = $s->hero_image_path ? global_asset($s->hero_image_path) : null;
  $catIcons = ['apartment'=>'🏢','house'=>'🏠','villa'=>'🏡','office'=>'🏬','commercial-property'=>'🏪','land'=>'🌳'];
@endphp

{{-- HERO --}}
<section style="position:relative;color:#fff;overflow:hidden">
  <div style="position:absolute;inset:0;background:linear-gradient(120deg,var(--re-accent-dark),var(--re-accent));z-index:0"></div>
  @if($hero)
    <img src="{{ $hero }}" alt="" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;opacity:.28;z-index:0">
  @endif
  <div class="re-container" style="position:relative;z-index:1;padding:84px 18px 96px">
    <span style="display:inline-block;background:rgba(255,255,255,.16);padding:6px 14px;border-radius:30px;font-size:13px;font-weight:600">{{ __('messages.FindYourDreamHome') }}</span>
    <h1 style="font-size:44px;line-height:1.1;font-weight:800;margin:16px 0 10px;max-width:720px">
      {{ $s->hero_title ?: __('messages.RealEstateHeroTitle') }}
    </h1>
    <p style="font-size:18px;opacity:.92;max-width:600px;margin:0 0 26px">
      {{ $s->hero_subtitle ?: __('messages.RealEstateHeroSubtitle') }}
    </p>

    {{-- Hero search --}}
    <form method="GET" action="{{ route('store.realestate.listings') }}"
          style="background:#fff;border-radius:14px;padding:14px;display:grid;grid-template-columns:1.4fr 1fr 1fr auto;gap:10px;max-width:880px;box-shadow:0 18px 40px rgba(15,33,43,.25)">
      <input class="re-input" type="text" name="q" placeholder="{{ __('messages.SearchByTitleOrKeyword') }}">
      <select class="re-select" name="category">
        <option value="">{{ __('messages.AllTypes') }}</option>
        @foreach($categories as $c)
          <option value="{{ $c->slug }}">{{ $c->name }}</option>
        @endforeach
      </select>
      <select class="re-select" name="purpose">
        <option value="">{{ __('messages.BuyOrRent') }}</option>
        <option value="sale">{{ __('messages.ForSale') }}</option>
        <option value="rent">{{ __('messages.ForRent') }}</option>
      </select>
      <button class="re-btn re-btn-primary" type="submit">🔍 {{ __('messages.Search') }}</button>
    </form>
  </div>
</section>

{{-- CATEGORIES --}}
@if($categories->count())
<section class="re-section" style="padding-bottom:0">
  <div class="re-container">
    <div class="re-section-head">
      <div><span class="re-kicker">{{ __('messages.Categories') }}</span><h2 class="re-title">{{ __('messages.PropertyCategories') }}</h2></div>
    </div>
    <div class="re-cat-grid">
      @foreach($categories as $c)
        <a class="re-cat" href="{{ route('store.realestate.listings', ['category' => $c->slug]) }}">
          <div class="ic">{{ $catIcons[$c->slug] ?? '🏘️' }}</div>
          <div class="nm">{{ $c->name }}</div>
          <div class="ct">{{ $c->properties_count }} {{ __('messages.Listings') }}</div>
        </a>
      @endforeach
    </div>
  </div>
</section>
@endif

{{-- FEATURED --}}
@if($featured->count())
<section class="re-section">
  <div class="re-container">
    <div class="re-section-head">
      <div><span class="re-kicker">{{ __('messages.Handpicked') }}</span><h2 class="re-title">{{ __('messages.FeaturedProperties') }}</h2></div>
      <a class="re-btn re-btn-ghost" href="{{ route('store.realestate.listings') }}">{{ __('messages.ViewAll') }}</a>
    </div>
    <div class="re-grid">
      @foreach($featured as $property)
        @include('store.realestate.partials.card', ['property' => $property, 'currency' => $currency])
      @endforeach
    </div>
  </div>
</section>
@endif

{{-- FEATURED LOCATIONS --}}
@if($locations->count())
<section class="re-section" style="padding-top:0">
  <div class="re-container">
    <div class="re-section-head">
      <div><span class="re-kicker">{{ __('messages.Explore') }}</span><h2 class="re-title">{{ __('messages.FeaturedLocations') }}</h2></div>
    </div>
    <div class="re-loc-grid">
      @foreach($locations as $loc)
        <a class="re-loc" href="{{ route('store.realestate.listings', ['location' => $loc->city]) }}">
          <span class="city">📍 {{ $loc->city }}</span>
          <span class="ct">{{ $loc->total }} {{ __('messages.Properties') }}</span>
        </a>
      @endforeach
    </div>
  </div>
</section>
@endif

{{-- LATEST --}}
@if($latest->count())
<section class="re-section" style="padding-top:0">
  <div class="re-container">
    <div class="re-section-head">
      <div><span class="re-kicker">{{ __('messages.JustListed') }}</span><h2 class="re-title">{{ __('messages.LatestProperties') }}</h2></div>
      <a class="re-btn re-btn-ghost" href="{{ route('store.realestate.listings', ['sort' => 'latest']) }}">{{ __('messages.ViewAll') }}</a>
    </div>
    <div class="re-grid cols-4">
      @foreach($latest as $property)
        @include('store.realestate.partials.card', ['property' => $property, 'currency' => $currency])
      @endforeach
    </div>
  </div>
</section>
@endif

@endsection
