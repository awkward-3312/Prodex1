<!DOCTYPE html>
@php
    $appName = \App\Models\Central\GeneralSetting::instance()->app_name ?: config('app.name', 'PRODEX');
    $home = \Illuminate\Support\Facades\Route::has('central.welcome') ? route('central.welcome') : url('/');
    $login = \Illuminate\Support\Facades\Route::has('central.login') ? route('central.login') : null;
@endphp
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="{{ config('seo.theme_color', '#0B1220') }}">
    <title>@yield('title') — {{ $appName }}</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('images/social/favicon-32x32.png') }}">
    <style>
        *,*::before,*::after{box-sizing:border-box}
        :root{--ink:#0F172A;--ink-2:#475569;--ink-3:#64748B;--line:#E7EAF0;--brand:#4F46E5;--bg:#F7F9FC}
        html,body{margin:0;padding:0}
        body{min-height:100vh;display:flex;flex-direction:column;background:var(--bg);color:var(--ink);
            font:16px/1.6 ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;
            -webkit-font-smoothing:antialiased}
        a{color:inherit}
        .wrap{flex:1;display:flex;align-items:center;justify-content:center;padding:40px 20px}
        .card{width:100%;max-width:560px;text-align:center}
        .brand{display:inline-flex;align-items:center;gap:10px;text-decoration:none;font-weight:700;color:var(--ink);margin-bottom:36px}
        .brand span{display:inline-grid;place-items:center;width:34px;height:34px;border-radius:9px;background:var(--ink);color:#fff;font-size:15px}
        .code{font-size:13px;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:var(--brand);margin:0 0 12px}
        h1{font-size:clamp(28px,5vw,40px);line-height:1.15;letter-spacing:-.02em;margin:0 0 14px}
        p.lead{font-size:17px;color:var(--ink-2);margin:0 auto 30px;max-width:44ch}
        .actions{display:flex;gap:12px;justify-content:center;flex-wrap:wrap}
        .btn{display:inline-flex;align-items:center;justify-content:center;min-height:44px;padding:11px 22px;border-radius:9999px;
            font-weight:600;font-size:15px;text-decoration:none;border:1px solid transparent;transition:background .12s ease,border-color .12s ease,color .12s ease}
        .btn--primary{background:var(--ink);color:#fff}
        .btn--primary:hover{background:#1e293b}
        .btn--ghost{background:#fff;color:var(--ink);border-color:var(--line)}
        .btn--ghost:hover{border-color:#cbd5e1}
        .btn:focus-visible{outline:3px solid rgba(79,70,229,.45);outline-offset:2px}
        footer{padding:22px 20px;text-align:center;font-size:13px;color:var(--ink-3)}
        @media (prefers-reduced-motion:reduce){*{transition:none!important}}
    </style>
</head>
<body>
    <main class="wrap">
        <div class="card">
            <a class="brand" href="{{ $home }}">
                <span aria-hidden="true">{{ strtoupper(substr($appName, 0, 1)) }}</span>{{ $appName }}
            </a>
            <p class="code">@yield('code')</p>
            <h1>@yield('title')</h1>
            <p class="lead">@yield('message')</p>
            <div class="actions">
                <a class="btn btn--primary" href="{{ $home }}">@lang('landing.home')</a>
                @if($login)
                    <a class="btn btn--ghost" href="{{ $login }}">@lang('landing.sign_in')</a>
                @endif
            </div>
        </div>
    </main>
    <footer>&copy; {{ date('Y') }} {{ $appName }}</footer>
</body>
</html>
