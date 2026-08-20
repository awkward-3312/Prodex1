@php
    $seoTitle = ($seo->meta_title ?? null) ?: ($appName ?? 'PRODEX');
    $seoDescription = ($seo->meta_description ?? null) ?: 'PRODEX es una plataforma ERP en la nube para gestionar ventas, inventario, compras, facturación y operaciones empresariales.';
    $seoCanonical = 'https://prodexhub.cloud/';
    $seoImage = !empty($seo->og_image) ? asset($seo->og_image) : ($logoUrl ?? null);
    $seoLocale = str_replace('-', '_', app()->getLocale());
@endphp

<meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">
<link rel="canonical" href="{{ $seoCanonical }}">

<meta property="og:type" content="website">
<meta property="og:site_name" content="PRODEX">
<meta property="og:url" content="{{ $seoCanonical }}">
<meta property="og:locale" content="{{ $seoLocale }}">

<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{{ $seoTitle }}">
<meta name="twitter:description" content="{{ $seoDescription }}">
@if($seoImage)
    <meta name="twitter:image" content="{{ $seoImage }}">
@endif

<script type="application/ld+json">
{!! json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'SoftwareApplication',
    'name' => 'PRODEX',
    'url' => $seoCanonical,
    'applicationCategory' => 'BusinessApplication',
    'operatingSystem' => 'Web',
    'description' => $seoDescription,
    'inLanguage' => app()->getLocale(),
    'image' => $seoImage,
    'publisher' => [
        '@type' => 'Organization',
        'name' => 'PRODEX',
        'url' => $seoCanonical,
        'logo' => $logoUrl ?: null,
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}
</script>
