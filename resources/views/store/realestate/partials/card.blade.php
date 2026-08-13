@php
  use App\Models\StoreSetting;
  $currency = $currency ?? optional(StoreSetting::first())->currency_code ?? '$';
  $img = $property->featured_image
        ? global_asset($property->featured_image)
        : (is_array($property->gallery) && count($property->gallery) ? global_asset($property->gallery[0]) : null);
  $loc = collect([$property->city, $property->region])->filter()->implode(', ');
  $statusKey = $property->status ?: 'available';
@endphp
<article class="re-card">
  <a class="re-card-media" href="{{ route('store.realestate.show', $property->slug) }}">
    @if($img)
      <img src="{{ $img }}" alt="{{ $property->title }}" loading="lazy">
    @else
      <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:#9aa7af;font-size:34px">🏡</div>
    @endif
    <div class="re-card-tags">
      @if($property->featured)<span class="re-badge re-badge-featured">{{ __('messages.Featured') }}</span>@endif
      @if($property->purpose === 'rent')
        <span class="re-badge re-badge-rent">{{ __('messages.ForRent') }}</span>
      @else
        <span class="re-badge re-badge-sale">{{ __('messages.ForSale') }}</span>
      @endif
      @if($statusKey === 'sold')<span class="re-badge re-badge-sold">{{ __('messages.Sold') }}</span>@endif
      @if($statusKey === 'rented')<span class="re-badge re-badge-rented">{{ __('messages.Rented') }}</span>@endif
    </div>
    <div class="re-card-price">
      {{ $currency }}{{ number_format((float) $property->price) }}@if($property->purpose === 'rent')<span style="font-weight:500;opacity:.8">/{{ __('messages.Month') }}</span>@endif
    </div>
  </a>
  <div class="re-card-body">
    @if($property->category)
      <span class="re-kicker" style="font-size:11px">{{ $property->category->name }}</span>
    @endif
    <h3 class="re-card-title">
      <a href="{{ route('store.realestate.show', $property->slug) }}">{{ $property->title }}</a>
    </h3>
    @if($loc)<div class="re-card-loc">📍 {{ $loc }}</div>@endif
    <div class="re-specs">
      @if(!is_null($property->bedrooms))<span>🛏 <b>{{ $property->bedrooms }}</b> {{ __('messages.Beds') }}</span>@endif
      @if(!is_null($property->bathrooms))<span>🛁 <b>{{ $property->bathrooms }}</b> {{ __('messages.Baths') }}</span>@endif
      @if(!is_null($property->area))<span>📐 <b>{{ rtrim(rtrim(number_format((float)$property->area, 2), '0'), '.') }}</b> {{ $property->area_unit }}</span>@endif
    </div>
  </div>
</article>
