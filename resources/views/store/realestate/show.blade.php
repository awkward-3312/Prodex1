@extends('store.realestate.layout')

@section('title', ($property->seo_title ?: $property->title) . ' — ' . ($s->store_name ?? ''))
@section('meta_description', $property->seo_description ?: \Illuminate\Support\Str::limit(strip_tags($property->description), 160))

@push('head')
  @if($property->seo_keywords)<meta name="keywords" content="{{ $property->seo_keywords }}">@endif
@endpush

@section('content')
@php
  $images = collect();
  if($property->featured_image) $images->push($property->featured_image);
  if(is_array($property->gallery)) foreach($property->gallery as $g){ if($g) $images->push($g); }
  $images = $images->unique()->values();
  $loc = collect([$property->address, $property->city, $property->region])->filter()->implode(', ');
  $amenities = is_array($property->amenities) ? array_filter($property->amenities) : [];
  $waNumber = preg_replace('/[^0-9]/', '', (string) $property->agent_whatsapp);
@endphp

<div class="re-container" style="padding-top:18px">
  <nav style="font-size:13px;color:var(--re-muted);display:flex;gap:8px;align-items:center;flex-wrap:wrap">
    <a href="{{ route('store.index') }}">{{ __('messages.Home') }}</a><span>/</span>
    <a href="{{ route('store.realestate.listings') }}">{{ __('messages.Properties') }}</a><span>/</span>
    <span style="color:var(--re-ink)">{{ $property->title }}</span>
  </nav>
</div>

{{-- HEADER --}}
<section class="re-section" style="padding:22px 0 0">
  <div class="re-container">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap">
      <div>
        <div style="display:flex;gap:6px;margin-bottom:8px">
          @if($property->featured)<span class="re-badge re-badge-featured">{{ __('messages.Featured') }}</span>@endif
          @if($property->purpose==='rent')<span class="re-badge re-badge-rent">{{ __('messages.ForRent') }}</span>@else<span class="re-badge re-badge-sale">{{ __('messages.ForSale') }}</span>@endif
          @if($property->status==='sold')<span class="re-badge re-badge-sold">{{ __('messages.Sold') }}</span>@endif
          @if($property->status==='rented')<span class="re-badge re-badge-rented">{{ __('messages.Rented') }}</span>@endif
        </div>
        <h1 style="margin:0;font-size:30px;font-weight:800">{{ $property->title }}</h1>
        @if($loc)<div class="re-card-loc" style="font-size:15px;margin-top:6px">📍 {{ $loc }}</div>@endif
      </div>
      <div style="text-align:right">
        <div style="font-size:30px;font-weight:800;color:var(--re-accent)">{{ $currency }}{{ number_format((float)$property->price) }}@if($property->purpose==='rent')<span style="font-size:16px;color:var(--re-muted);font-weight:500">/{{ __('messages.Month') }}</span>@endif</div>
        @if($property->category)<div style="color:var(--re-muted);font-size:14px">{{ $property->category->name }}</div>@endif
      </div>
    </div>
  </div>
</section>

{{-- GALLERY --}}
<section class="re-section" style="padding:18px 0 0">
  <div class="re-container">
    @if($images->count())
      <div style="border-radius:16px;overflow:hidden;background:#e9eef0">
        <img id="reMainImg" src="{{ global_asset($images[0]) }}" alt="{{ $property->title }}" style="width:100%;max-height:520px;object-fit:cover">
      </div>
      @if($images->count() > 1)
        <div style="display:flex;gap:10px;margin-top:12px;overflow-x:auto;padding-bottom:4px">
          @foreach($images as $img)
            <img src="{{ global_asset($img) }}" alt="" onclick="document.getElementById('reMainImg').src=this.src"
                 style="width:110px;height:80px;object-fit:cover;border-radius:10px;cursor:pointer;flex:0 0 auto;border:2px solid transparent" loading="lazy"
                 onmouseover="this.style.borderColor='var(--re-accent)'" onmouseout="this.style.borderColor='transparent'">
          @endforeach
        </div>
      @endif
    @else
      <div style="border-radius:16px;background:#e9eef0;height:340px;display:flex;align-items:center;justify-content:center;font-size:54px;color:#9aa7af">🏡</div>
    @endif
  </div>
</section>

{{-- BODY --}}
<section class="re-section" style="padding-top:30px">
  <div class="re-container" style="display:grid;grid-template-columns:1fr 360px;gap:26px;align-items:start">

    {{-- LEFT --}}
    <div>
      {{-- Specs --}}
      <div class="re-card-pad" style="margin-bottom:22px">
        <h3 style="margin:0 0 16px;font-size:18px">{{ __('messages.PropertyDetails') }}</h3>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:14px">
          @php
            $specs = [
              ['🏷️', __('messages.PropertyType'), $property->category->name ?? '—'],
              ['💰', __('messages.Price'), $currency.number_format((float)$property->price)],
              ['📐', __('messages.Area'), !is_null($property->area) ? rtrim(rtrim(number_format((float)$property->area,2),'0'),'.').' '.$property->area_unit : '—'],
              ['🛏', __('messages.Bedrooms'), $property->bedrooms ?? '—'],
              ['🛁', __('messages.Bathrooms'), $property->bathrooms ?? '—'],
              ['🚗', __('messages.Garage'), $property->garage ?? '—'],
              ['📍', __('messages.Location'), $property->city ?: '—'],
              ['🔖', __('messages.Status'), __('messages.'.ucfirst($property->status))],
            ];
          @endphp
          @foreach($specs as $sp)
            <div style="background:var(--re-bg);border-radius:10px;padding:12px 14px">
              <div style="font-size:12px;color:var(--re-muted)">{{ $sp[0] }} {{ $sp[1] }}</div>
              <div style="font-weight:700;margin-top:3px">{{ $sp[2] }}</div>
            </div>
          @endforeach
        </div>
      </div>

      {{-- Description --}}
      @if($property->description)
        <div class="re-card-pad" style="margin-bottom:22px">
          <h3 style="margin:0 0 12px;font-size:18px">{{ __('messages.Description') }}</h3>
          <div style="color:#33424d;font-size:15px;white-space:pre-line">{{ $property->description }}</div>
        </div>
      @endif

      {{-- Amenities --}}
      @if(count($amenities))
        <div class="re-card-pad" style="margin-bottom:22px">
          <h3 style="margin:0 0 12px;font-size:18px">{{ __('messages.FeaturesAmenities') }}</h3>
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px">
            @foreach($amenities as $a)
              <div style="display:flex;align-items:center;gap:8px;font-size:14px"><span style="color:var(--re-accent)">✓</span> {{ $a }}</div>
            @endforeach
          </div>
        </div>
      @endif

      {{-- Map --}}
      @if($loc)
        <div class="re-card-pad">
          <h3 style="margin:0 0 12px;font-size:18px">{{ __('messages.LocationMap') }}</h3>
          <div style="border-radius:12px;overflow:hidden;aspect-ratio:16/9">
            <iframe src="https://www.google.com/maps?q={{ urlencode($loc) }}&output=embed" style="width:100%;height:100%;border:0" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
          </div>
        </div>
      @endif
    </div>

    {{-- RIGHT: agent + inquiry --}}
    <aside style="position:sticky;top:84px;display:flex;flex-direction:column;gap:18px">
      @if($property->agent_name || $property->agent_phone || $property->agent_email)
        <div class="re-card-pad">
          <h3 style="margin:0 0 14px;font-size:17px">{{ __('messages.ContactAgent') }}</h3>
          <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px">
            <div style="width:48px;height:48px;border-radius:50%;background:var(--re-accent);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:18px">
              {{ strtoupper(mb_substr($property->agent_name ?: 'A', 0, 1)) }}
            </div>
            <div>
              <div style="font-weight:700">{{ $property->agent_name ?: ($s->store_name ?? __('messages.SalesTeam')) }}</div>
              <div style="font-size:13px;color:var(--re-muted)">{{ __('messages.PropertyAgent') }}</div>
            </div>
          </div>
          <div style="display:flex;flex-direction:column;gap:8px">
            @if($property->agent_phone)
              <a class="re-btn re-btn-ghost re-btn-block" href="tel:{{ preg_replace('/\s+/','',$property->agent_phone) }}">📞 {{ $property->agent_phone }}</a>
            @endif
            @if($property->agent_email)
              <a class="re-btn re-btn-ghost re-btn-block" href="mailto:{{ $property->agent_email }}">✉️ {{ __('messages.EmailAgent') }}</a>
            @endif
            @if($waNumber)
              <a class="re-btn re-btn-wa re-btn-block" target="_blank" rel="noopener"
                 href="https://wa.me/{{ $waNumber }}?text={{ urlencode(__('messages.WhatsAppInquiryPrefix').' '.$property->title) }}">🟢 {{ __('messages.WhatsAppInquiry') }}</a>
            @endif
          </div>
        </div>
      @endif

      {{-- Inquiry form --}}
      <div class="re-card-pad">
        <h3 style="margin:0 0 14px;font-size:17px">{{ __('messages.SendInquiry') }}</h3>
        <div id="reInquiryAlert" class="re-alert re-hidden"></div>
        <form id="reInquiryForm" method="POST" action="{{ route('store.realestate.inquiry') }}">
          @csrf
          <input type="hidden" name="property_id" value="{{ $property->id }}">
          <div class="re-field">
            <label class="re-label">{{ __('messages.YourName') }} *</label>
            <input class="re-input" type="text" name="name" required>
          </div>
          <div class="re-field">
            <label class="re-label">{{ __('messages.Phone') }}</label>
            <input class="re-input" type="text" name="phone">
          </div>
          <div class="re-field">
            <label class="re-label">{{ __('messages.Email') }}</label>
            <input class="re-input" type="email" name="email">
          </div>
          <div class="re-field">
            <label class="re-label">{{ __('messages.Message') }} *</label>
            <textarea class="re-textarea" name="message" rows="4" required>{{ __('messages.InquiryDefaultMessage') }} "{{ $property->title }}".</textarea>
          </div>
          <div style="position:absolute;left:-10000px"><input type="text" name="company" tabindex="-1" autocomplete="off"></div>
          <button class="re-btn re-btn-primary re-btn-block" type="submit" id="reInquiryBtn">{{ __('messages.SendInquiry') }}</button>
        </form>
      </div>
    </aside>
  </div>
</section>

{{-- RELATED --}}
@if($related->count())
<section class="re-section" style="padding-top:10px">
  <div class="re-container">
    <div class="re-section-head">
      <div><span class="re-kicker">{{ __('messages.YouMayAlsoLike') }}</span><h2 class="re-title">{{ __('messages.RelatedProperties') }}</h2></div>
    </div>
    <div class="re-grid">
      @foreach($related as $rp)
        @include('store.realestate.partials.card', ['property' => $rp, 'currency' => $currency])
      @endforeach
    </div>
  </div>
</section>
@endif

@push('scripts')
<script>
(function(){
  var form = document.getElementById('reInquiryForm');
  var btn  = document.getElementById('reInquiryBtn');
  var box  = document.getElementById('reInquiryAlert');
  if(!form) return;
  form.addEventListener('submit', function(e){
    e.preventDefault();
    box.className = 're-alert re-hidden';
    var original = btn.textContent;
    btn.disabled = true; btn.textContent = '{{ __('messages.Sending') }}';
    fetch(form.action, {
      method:'POST',
      headers:{ 'Accept':'application/json', 'X-CSRF-TOKEN': form.querySelector('input[name=_token]').value },
      body: new FormData(form)
    })
    .then(function(r){ return r.json().then(function(d){ return {ok:r.ok, status:r.status, data:d}; }); })
    .then(function(res){
      if(res.ok){
        box.className = 're-alert re-alert-ok';
        box.textContent = (res.data && res.data.message) || '{{ __('messages.InquirySent') }}';
        form.reset();
        var pid = form.querySelector('input[name=property_id]'); if(pid) pid.value = '{{ $property->id }}';
      } else if(res.status === 422 && res.data && res.data.errors){
        var first = Object.values(res.data.errors)[0];
        box.className = 're-alert re-alert-err';
        box.textContent = (first && first[0]) || '{{ __('messages.SomethingWentWrong') }}';
      } else {
        box.className = 're-alert re-alert-err';
        box.textContent = '{{ __('messages.SomethingWentWrong') }}';
      }
    })
    .catch(function(){ box.className = 're-alert re-alert-err'; box.textContent = '{{ __('messages.NetworkError') }}'; })
    .finally(function(){ btn.disabled = false; btn.textContent = original; });
  });
})();
</script>
@endpush
@endsection
