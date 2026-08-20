<!DOCTYPE html>
@php
    $generalSettings = \App\Models\Central\GeneralSetting::instance();
    $appName = $generalSettings->app_name ?: 'PRODEX';
    $logoUrl = $generalSettings->getLogoUrl();
    $canonical = url()->current();
@endphp
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $page['title'] }}</title>
    <meta name="description" content="{{ $page['description'] }}">
    <meta name="robots" content="index, follow, max-image-preview:large">
    <link rel="canonical" href="{{ $canonical }}">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="PRODEX">
    <meta property="og:title" content="{{ $page['title'] }}">
    <meta property="og:description" content="{{ $page['description'] }}">
    <meta property="og:url" content="{{ $canonical }}">
    <meta name="twitter:card" content="summary">
    <link href="{{ asset('assets_super/css/inter.css') }}" rel="stylesheet">
    <link href="{{ asset('assets_super/css/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ asset('assets_super/css/bootstrap-icons.min.css') }}" rel="stylesheet">
    <link href="{{ asset('assets_super/css/landing.css') }}" rel="stylesheet">
    <script type="application/ld+json">{!! json_encode(['@context'=>'https://schema.org','@type'=>'WebPage','name'=>$page['heading'],'description'=>$page['description'],'url'=>$canonical,'about'=>['@type'=>'SoftwareApplication','name'=>'PRODEX','applicationCategory'=>'BusinessApplication','operatingSystem'=>'Web']], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) !!}</script>
    <style>
        .seo-page{background:#f8fafc;color:#172033;min-height:100vh}.seo-nav{background:#fff;border-bottom:1px solid #e7eaf0;padding:18px 0}.seo-brand{display:flex;align-items:center;gap:10px;color:#172033;text-decoration:none;font-weight:700;font-size:20px}.seo-brand img{max-height:38px;max-width:150px}.seo-hero{padding:90px 0 65px;background:#fff}.seo-kicker{font-weight:700;color:#6366f1;text-transform:uppercase;letter-spacing:.08em;font-size:13px}.seo-hero h1{font-size:clamp(38px,6vw,64px);line-height:1.05;max-width:850px;margin:16px 0 24px}.seo-lead{font-size:20px;line-height:1.7;max-width:800px;color:#536074}.seo-cta{display:inline-block;margin-top:22px;padding:13px 22px;border-radius:9px;background:#172033;color:#fff;text-decoration:none;font-weight:700}.seo-content{padding:70px 0}.seo-content h2{font-size:34px;margin-bottom:18px}.seo-content p{font-size:17px;line-height:1.8;color:#536074}.seo-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:22px;margin-top:35px}.seo-card{background:#fff;border:1px solid #e7eaf0;border-radius:16px;padding:28px}.seo-card h3{font-size:20px;margin-bottom:12px}.seo-links{padding:50px 0;background:#fff}.seo-links a{margin-right:20px}.seo-footer{padding:35px 0;text-align:center;color:#6b7280}@media(max-width:768px){.seo-grid{grid-template-columns:1fr}.seo-hero{padding:60px 0 45px}}
    </style>
</head>
<body class="seo-page">
<nav class="seo-nav"><div class="container d-flex justify-content-between align-items-center"><a class="seo-brand" href="{{ route('central.welcome') }}">@if($logoUrl)<img src="{{ $logoUrl }}" alt="PRODEX">@endif <span>{{ $appName }}</span></a><a href="{{ route('central.welcome') }}" class="btn btn-outline-dark">Conoce PRODEX</a></div></nav>
<header class="seo-hero"><div class="container"><div class="seo-kicker">PRODEX · Gestión empresarial en la nube</div><h1>{{ $page['heading'] }}</h1><p class="seo-lead">{{ $page['intro'] }}</p><a class="seo-cta" href="{{ route('central.welcome') }}#pricing">Conoce nuestros planes</a></div></header>
<main class="seo-content"><div class="container"><h2>{{ $page['section_title'] }}</h2><p>{{ $page['section_text'] }}</p><div class="seo-grid">@foreach($page['benefits'] as $benefit)<article class="seo-card"><h3>{{ $benefit['title'] }}</h3><p>{{ $benefit['text'] }}</p></article>@endforeach</div></div></main>
<section class="seo-links"><div class="container"><h2>Conoce más soluciones de PRODEX</h2><p>Explora las herramientas de gestión que puedes centralizar en una sola plataforma.</p><a href="{{ url('/sistema-pos-honduras') }}">Sistema POS</a><a href="{{ url('/software-inventario-honduras') }}">Control de inventario</a><a href="{{ url('/erp-honduras') }}">ERP para empresas</a></div></section>
<footer class="seo-footer"><div class="container">© {{ date('Y') }} PRODEX · Smart Business Management Solution</div></footer>
</body>
</html>
