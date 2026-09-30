<!DOCTYPE html>
@php
    $appName = \App\Models\Central\GeneralSetting::instance()->app_name ?: config('app.name', 'PRODEX');
    $home = \Illuminate\Support\Facades\Route::has('central.welcome') ? route('central.welcome') : url('/');
    $login = \Illuminate\Support\Facades\Route::has('central.login') ? route('central.login') : null;
@endphp
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <link rel="stylesheet" href="{{ global_asset('css/prodex-brand.css') }}">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="{{ config('seo.theme_color', '#0B1220') }}">
    <title>@yield('title') — {{ $appName }}</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('images/brand-assets/icons/icon-32.png') }}">
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
        @hasSection('illustration')
        /* Solo se carga cuando una página de error concreta rellena la sección `illustration`
           — 500 sigue exactamente igual, sin nada de este bloque. Estructura de "hero card"
           compartida entre variantes (404 espacial, 403 pencil); cada variante (`.card--space`,
           `.card--pencil`) solo aporta su propia paleta/fondo — así 404 y 403 comparten
           tipografía/spacing/botones sin compartir ilustración ni layout de fondo. */
        :root{--pxerr-navy:var(--prodex-ink);--pxerr-navy-2:var(--prodex-ink);--pxerr-cyan:var(--prodex-aqua);--pxerr-star:#eef4ff}
        .wrap{padding:24px}
        .card{position:relative;overflow:hidden;max-width:1200px;min-height:min(640px,calc(100vh - 48px));
            display:flex;align-items:center;text-align:left;border-radius:16px}
        .card-body{position:relative;z-index:3;max-width:640px;padding:48px 48px 48px clamp(28px,6vw,72px)}
        .code{display:block;font-size:clamp(80px,12vw,140px);font-weight:800;line-height:.9;letter-spacing:-.03em;text-transform:none;margin:0 0 8px}
        h1{font-size:clamp(22px,3vw,28px)}
        p.lead{margin:0 0 28px}
        .actions{justify-content:flex-start}
        @media (max-width:860px){
            .card{flex-direction:column;min-height:auto}
            .card-body{max-width:none;text-align:center;padding:244px 24px 32px}
            .actions{justify-content:center}
            .actions .btn{flex:1 1 auto}
        }
        .illustration{position:absolute;inset:0;z-index:0}

        /* Variante 404 — hero espacial navy de punta a punta (astronauta + estrellas). */
        .card--space{color:#fff;
            background:radial-gradient(60% 55% at 74% 68%,rgba(6,182,212,.20) 0%,transparent 60%),
                       radial-gradient(90% 70% at 18% 22%,var(--pxerr-navy-2) 0%,transparent 60%),
                       linear-gradient(155deg,var(--pxerr-navy-2) 0%,var(--pxerr-navy) 68%)}
        .card--space .brand{color:#fff;margin-bottom:28px}
        .card--space .brand span{background:#fff;color:var(--pxerr-navy)}
        .card--space .code{background:linear-gradient(180deg,#fff 0%,var(--pxerr-cyan) 130%);-webkit-background-clip:text;background-clip:text;color:transparent;
            text-shadow:0 0 34px rgba(6,182,212,.45)}
        .card--space h1{color:#fff}
        .card--space p.lead{color:rgba(255,255,255,.72)}
        .card--space .btn--ghost{background:transparent;color:#fff;border-color:rgba(255,255,255,.28)}
        .card--space .btn--ghost:hover{background:rgba(255,255,255,.1);border-color:rgba(255,255,255,.28)}
        .pxerr-orbit{position:absolute;top:8%;right:-12%;width:62%;aspect-ratio:1;border:1px solid rgba(6,182,212,.16);border-radius:50%;transform:rotate(-18deg)}
        .pxerr-glow{position:absolute;top:30%;right:10%;width:34%;aspect-ratio:1;border-radius:50%;
            background:radial-gradient(circle,rgba(6,182,212,.35) 0%,transparent 70%);filter:blur(6px)}
        .pxerr-stars,.pxerr-starlayer{position:absolute;inset:0}
        .pxerr-star{position:absolute;border-radius:50%;background:var(--pxerr-star);opacity:0;
            animation-name:pxerr-twinkle-fall;animation-timing-function:linear;animation-iteration-count:infinite}
        .pxerr-star--sm{width:2px;height:2px}
        .pxerr-star--md{width:3px;height:3px}
        .pxerr-star--lg{width:4px;height:4px;box-shadow:0 0 4px 1px rgba(255,255,255,.35)}
        .pxerr-starlayer--2 .pxerr-star{animation-delay:-3s}
        .pxerr-starlayer--3 .pxerr-star{animation-delay:-6s}
        @keyframes pxerr-twinkle-fall{0%{opacity:0;transform:translateY(0)}12%{opacity:.9}100%{opacity:0;transform:translateY(120%)}}
        /* Geometría fiel 1:1 al original de Uiverse — canvas lógico 250×300, coordenadas exactas.
           OUTER (.pxerr-astro): posición + flotación. INNER (.pxerr-astro__scale): solo scale(),
           fijo 250×300, transform-origin top left — nunca compiten en el mismo transform. */
        .pxerr-astro{--pxerr-astro-scale:0.82;position:absolute;z-index:2;top:50%;right:10%;
            width:calc(250px * var(--pxerr-astro-scale));height:calc(300px * var(--pxerr-astro-scale));
            margin-top:calc(-150px * var(--pxerr-astro-scale));animation:pxerr-float 6.5s ease-in-out infinite}
        .pxerr-astro__scale{position:absolute;top:0;left:0;width:250px;height:300px;
            transform:scale(var(--pxerr-astro-scale));transform-origin:top left}
        @keyframes pxerr-float{0%{transform:translateY(0) rotate(-4deg)}50%{transform:translateY(-10px) rotate(4deg)}100%{transform:translateY(0) rotate(-4deg)}}
        @media (max-width:860px){
            .pxerr-astro{--pxerr-astro-scale:0.5;top:20px;left:50%;right:auto;margin-top:0;
                margin-left:calc(-125px * var(--pxerr-astro-scale))}
        }
        @media (min-width:861px) and (max-width:1080px){ .pxerr-astro{--pxerr-astro-scale:0.62;right:6%} }
        .pxerr-astro__schoolbag{position:absolute;z-index:1;width:100px;height:150px;top:calc(50% - 75px);left:calc(50% - 50px);
            background:#94b7ca;border-radius:50px 50px 0 0/30px 30px 0 0}
        .pxerr-astro__body{position:absolute;z-index:2;width:85px;height:100px;top:105px;left:calc(50% - 41px);
            background:linear-gradient(90deg,#e3e8eb 0%,#e3e8eb 50%,#fbfdfa 50%,#fbfdfa 100%);border-radius:40px/20px}
        .pxerr-astro__panel{position:absolute;width:60px;height:40px;top:20px;left:calc(50% - 30px);background:#b7cceb}
        .pxerr-astro__panel::before{content:"";position:absolute;width:30px;height:5px;top:9px;left:7px;
            background:#fbfdfa;box-shadow:0 9px 0 #fbfdfa,0 18px 0 #fbfdfa}
        .pxerr-astro__panel::after{content:"";position:absolute;width:8px;height:8px;top:9px;right:7px;
            background:#fbfdfa;border-radius:50%;box-shadow:0 14px 0 2px #fbfdfa}
        .pxerr-astro__arm{position:absolute;z-index:2;width:80px;height:30px;top:121px}
        .pxerr-astro__arm--left{left:30px;background:#e3e8eb;border-radius:0 0 0 39px}
        .pxerr-astro__arm--right{right:30px;background:#fbfdfa;border-radius:0 0 39px 0}
        .pxerr-astro__arm--left::before,.pxerr-astro__arm--right::before{content:"";position:absolute;width:30px;height:70px;top:-40px}
        .pxerr-astro__arm--left::before{left:0;background:#e3e8eb;border-radius:50px 50px 0 120px/50px 50px 0 110px}
        .pxerr-astro__arm--right::before{right:0;background:#fbfdfa;border-radius:50px 50px 120px 0/50px 50px 110px 0}
        .pxerr-astro__arm--left::after,.pxerr-astro__arm--right::after{content:"";position:absolute;width:30px;height:10px;top:-24px}
        .pxerr-astro__arm--left::after{left:0;background:#6e91a4}
        .pxerr-astro__arm--right::after{right:0;background:#b6d2e0}
        .pxerr-astro__leg{position:absolute;z-index:2;width:30px;height:40px;bottom:70px}
        .pxerr-astro__leg--left{left:76px;background:#e3e8eb;transform:rotate(20deg)}
        .pxerr-astro__leg--right{right:73px;background:#fbfdfa;transform:rotate(-20deg)}
        .pxerr-astro__leg--left::before,.pxerr-astro__leg--right::before{content:"";position:absolute;width:50px;height:25px;bottom:-26px}
        .pxerr-astro__leg--left::before{left:-20px;background:#e3e8eb;border-radius:30px 0 0 0;border-bottom:10px solid #6d96ac}
        .pxerr-astro__leg--right::before{right:-20px;background:#fbfdfa;border-radius:0 30px 0 0;border-bottom:10px solid #b0cfe4}
        .pxerr-astro__head{position:absolute;z-index:3;width:97px;height:80px;top:34px;left:calc(50% - 47.5px);
            background:linear-gradient(90deg,#e3e8eb 0%,#e3e8eb 50%,#fbfdfa 50%,#fbfdfa 100%);border-radius:50%}
        .pxerr-astro__head::after{content:"";position:absolute;width:60px;height:50px;top:calc(50% - 25px);left:calc(50% - 30px);
            background:linear-gradient(180deg,#15aece 0%,#15aece 50%,#0391bf 50%,#0391bf 100%);border-radius:15px}
        .pxerr-astro__head::before{content:"";position:absolute;width:12px;height:25px;top:calc(50% - 12.5px);left:-4px;
            background:#618095;border-radius:5px;box-shadow:92px 0 0 #618095}
        @media (prefers-reduced-motion:reduce){.pxerr-star,.pxerr-astro{animation:none}.pxerr-star{opacity:.6}.pxerr-astro{transform:none}}

        /* Variante 403 — superficie clara PRODEX (no el espacio de 404): el lápiz de Uiverse,
           geometría 1:1, con un acento navy/cyan CONTENIDO detrás (no todo el card en navy). */
        .card--pencil{background:var(--bg)}
        .card--pencil .code{background:linear-gradient(180deg,var(--pxerr-navy-2) 0%,var(--pxerr-cyan) 130%);
            -webkit-background-clip:text;background-clip:text;color:transparent}
        .card--pencil .illustration{position:absolute;top:50%;right:6%;left:auto;bottom:auto;transform:translateY(-50%);
            width:clamp(220px,30vw,340px);height:clamp(220px,30vw,340px);display:flex;align-items:center;justify-content:center;z-index:0}
        .card--pencil .illustration::before{content:"";position:absolute;inset:8%;border-radius:50%;
            background:radial-gradient(circle,rgba(6,182,212,.16) 0%,rgba(15,42,74,.10) 55%,transparent 78%)}
        @media (max-width:860px){
            /* El lápiz pasa a flujo normal (ya no es absolute como el astronauta de 404), así
               que no necesita el padding-top reservado — solo su propio margin-bottom. */
            .card--pencil .illustration{position:relative;top:0;right:0;left:auto;bottom:auto;transform:none;margin:0 auto 8px;width:clamp(160px,42vw,220px);height:clamp(160px,42vw,220px)}
            .card--pencil .card-body{padding-top:8px}
        }
        .pencil{position:relative;z-index:1;display:block;width:clamp(160px,20vw,220px);height:auto;color:var(--ink-3)}
        @media (max-width:860px){ .pencil{width:clamp(130px,38vw,180px)} }
        .pencil__body1,.pencil__body2,.pencil__body3,.pencil__eraser,.pencil__eraser-skew,.pencil__point,.pencil__rotate,.pencil__stroke{
            animation-duration:3s;animation-timing-function:linear;animation-iteration-count:infinite}
        .pencil__body1,.pencil__body2,.pencil__body3{transform:rotate(-90deg)}
        .pencil__body1{animation-name:pencilBody1}
        .pencil__body2{animation-name:pencilBody2}
        .pencil__body3{animation-name:pencilBody3}
        .pencil__eraser{animation-name:pencilEraser;transform:rotate(-90deg) translate(49px,0)}
        .pencil__eraser-skew{animation-name:pencilEraserSkew;animation-timing-function:ease-in-out}
        .pencil__point{animation-name:pencilPoint;transform:rotate(-90deg) translate(49px,-30px)}
        .pencil__rotate{animation-name:pencilRotate}
        .pencil__stroke{animation-name:pencilStroke;transform:translate(100px,100px) rotate(-113deg)}
        @keyframes pencilBody1{from,to{stroke-dashoffset:351.86;transform:rotate(-90deg)}50%{stroke-dashoffset:150.8;transform:rotate(-225deg)}}
        @keyframes pencilBody2{from,to{stroke-dashoffset:406.84;transform:rotate(-90deg)}50%{stroke-dashoffset:174.36;transform:rotate(-225deg)}}
        @keyframes pencilBody3{from,to{stroke-dashoffset:296.88;transform:rotate(-90deg)}50%{stroke-dashoffset:127.23;transform:rotate(-225deg)}}
        @keyframes pencilEraser{from,to{transform:rotate(-45deg) translate(49px,0)}50%{transform:rotate(0deg) translate(49px,0)}}
        @keyframes pencilEraserSkew{from,32.5%,67.5%,to{transform:skewX(0)}35%,65%{transform:skewX(-4deg)}37.5%,62.5%{transform:skewX(8deg)}40%,45%,50%,55%,60%{transform:skewX(-15deg)}42.5%,47.5%,52.5%,57.5%{transform:skewX(15deg)}}
        @keyframes pencilPoint{from,to{transform:rotate(-90deg) translate(49px,-30px)}50%{transform:rotate(-225deg) translate(49px,-30px)}}
        @keyframes pencilRotate{from{transform:translate(100px,100px) rotate(0)}to{transform:translate(100px,100px) rotate(720deg)}}
        @keyframes pencilStroke{from{stroke-dashoffset:439.82;transform:translate(100px,100px) rotate(-113deg)}50%{stroke-dashoffset:164.93;transform:translate(100px,100px) rotate(-113deg)}75%,to{stroke-dashoffset:439.82;transform:translate(100px,100px) rotate(112deg)}}
        /* stroke-dashoffset es un atributo SVG, no CSS: al quitar la animación cada pieza vuelve a
           su transform/atributo YA definidos arriba (los mismos del original) — ninguno se rompe
           ni queda a medio dibujar. */
        @media (prefers-reduced-motion:reduce){
            .pencil__body1,.pencil__body2,.pencil__body3,.pencil__eraser,.pencil__eraser-skew,.pencil__point,.pencil__rotate,.pencil__stroke{animation:none}
        }
        @endif
        @media (prefers-reduced-motion:reduce){*{transition:none!important}}
    </style>
</head>
<body>
    <main class="wrap">
        <div class="card @yield('card_variant')">
            @hasSection('illustration')
                @yield('illustration')
            @endif
            <div class="card-body">
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
        </div>
    </main>
    <footer>&copy; {{ date('Y') }} {{ $appName }}</footer>
</body>
</html>
