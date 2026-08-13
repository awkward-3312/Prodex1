@php
    $banner = app(\App\Services\TenantLimitsService::class)->getSubscriptionBanner();
@endphp

@if($banner)
@php
    $level = $banner['level'] === 'danger' ? 'danger' : 'warning';
    // Dismiss key changes with the reference date so a new reminder window re-shows it.
    $dismissKey = 'subreminder_' . $banner['type'] . '_' . $banner['ref'];

    $titles = [
        'trial'   => __('central.banner_trial_title'),
        'expiry'  => __('central.banner_expiry_title'),
        'expired' => __('central.banner_expired_title'),
    ];
    $texts = [
        'trial'   => __('central.banner_trial_text', ['days' => $banner['days'], 'date' => $banner['date']]),
        'expiry'  => __('central.banner_expiry_text', ['plan' => $banner['plan_name'], 'days' => $banner['days'], 'date' => $banner['date']]),
        'expired' => __('central.banner_expired_text', ['plan' => $banner['plan_name'], 'date' => $banner['date']]),
    ];
    $title = $titles[$banner['type']] ?? '';
    $text  = $texts[$banner['type']] ?? '';
@endphp

<div id="subReminderBanner" class="sub-reminder-banner sub-reminder-{{ $level }}" data-dismiss-key="{{ $dismissKey }}" role="alert" hidden>
    <div class="sub-reminder-inner">
        <span class="sub-reminder-icon">
            @if($banner['type'] === 'expired')
                <i class="bi bi-exclamation-octagon-fill"></i>
            @else
                <i class="bi bi-alarm-fill"></i>
            @endif
        </span>
        <div class="sub-reminder-body">
            <strong class="sub-reminder-title">{{ $title }}</strong>
            <span class="sub-reminder-text">{{ $text }}</span>
        </div>
        <a href="{{ route('billing.plans') }}" class="sub-reminder-cta">{{ __('central.banner_renew') }}</a>
        <button type="button" class="sub-reminder-close" id="subReminderClose" aria-label="{{ __('central.banner_dismiss') }}">&times;</button>
    </div>
</div>

<style>
    .sub-reminder-banner{position:fixed;left:50%;bottom:18px;transform:translateX(-50%);z-index:99999;max-width:760px;width:calc(100% - 32px);border-radius:12px;box-shadow:0 8px 28px rgba(0,0,0,.18);font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;animation:subReminderUp .35s ease}
    .sub-reminder-inner{display:flex;align-items:center;gap:14px;padding:14px 18px}
    .sub-reminder-warning{background:#fffbeb;border:1px solid #fcd34d}
    .sub-reminder-danger{background:#fef2f2;border:1px solid #fca5a5}
    .sub-reminder-icon{font-size:22px;line-height:1;flex:0 0 auto}
    .sub-reminder-warning .sub-reminder-icon{color:#d97706}
    .sub-reminder-danger .sub-reminder-icon{color:#dc2626}
    .sub-reminder-body{display:flex;flex-direction:column;gap:2px;flex:1 1 auto;min-width:0}
    .sub-reminder-title{font-size:14px;font-weight:700;color:#1e293b}
    .sub-reminder-text{font-size:13px;color:#475569;line-height:1.45}
    .sub-reminder-cta{flex:0 0 auto;display:inline-block;padding:9px 18px;border-radius:8px;font-size:13px;font-weight:600;text-decoration:none;color:#fff;white-space:nowrap}
    .sub-reminder-warning .sub-reminder-cta{background:#d97706}
    .sub-reminder-danger .sub-reminder-cta{background:#dc2626}
    .sub-reminder-cta:hover{filter:brightness(.94)}
    .sub-reminder-close{flex:0 0 auto;background:transparent;border:0;font-size:24px;line-height:1;color:#94a3b8;cursor:pointer;padding:0 4px}
    .sub-reminder-close:hover{color:#475569}
    @keyframes subReminderUp{from{opacity:0;transform:translate(-50%,16px)}to{opacity:1;transform:translate(-50%,0)}}
    @media (max-width:560px){.sub-reminder-inner{flex-wrap:wrap}.sub-reminder-cta{order:3}}
</style>

<script>
    (function () {
        var el = document.getElementById('subReminderBanner');
        if (!el) return;
        var key = el.getAttribute('data-dismiss-key');
        try {
            if (key && window.localStorage && localStorage.getItem(key) === '1') {
                el.parentNode && el.parentNode.removeChild(el);
                return;
            }
        } catch (e) {}
        el.hidden = false;
        var close = document.getElementById('subReminderClose');
        if (close) {
            close.addEventListener('click', function () {
                try { if (key && window.localStorage) localStorage.setItem(key, '1'); } catch (e) {}
                el.parentNode && el.parentNode.removeChild(el);
            });
        }
    })();
</script>
@endif
