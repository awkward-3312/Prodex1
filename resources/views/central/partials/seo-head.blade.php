@php
    $seoTitle = ($seo->meta_title ?? null) ?: ($appName ?? 'PRODEX');
    $seoDescription = ($seo->meta_description ?? null) ?: 'PRODEX es una plataforma ERP en la nube para gestionar ventas, inventario, compras, facturación y operaciones empresariales.';
    $seoCanonical = 'https://prodexhub.cloud/';
    $seoImage = !empty($seo->og_image) ? asset($seo->og_image) : ($logoUrl ?? null);
    $seoLocale = str_replace('-', '_', app()->getLocale());
@endphp

<meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">
<link rel="canonical" href="{{ $seoCanonical }}">
<meta name="geo.region" content="HN">
<meta name="geo.placename" content="Honduras">

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
    '@graph' => [
        [
            '@type' => 'Organization',
            '@id' => $seoCanonical . '#organization',
            'name' => 'PRODEX',
            'alternateName' => 'PRODEX Honduras',
            'url' => $seoCanonical,
            'logo' => $logoUrl ?: null,
            'areaServed' => ['@type' => 'Country', 'name' => 'Honduras'],
        ],
        [
            '@type' => 'WebSite',
            '@id' => $seoCanonical . '#website',
            'name' => 'PRODEX',
            'url' => $seoCanonical,
            'inLanguage' => app()->getLocale(),
            'publisher' => ['@id' => $seoCanonical . '#organization'],
        ],
        [
            '@type' => 'SoftwareApplication',
            '@id' => $seoCanonical . '#software',
            'name' => 'PRODEX',
            'url' => $seoCanonical,
            'applicationCategory' => 'BusinessApplication',
            'operatingSystem' => 'Web',
            'description' => $seoDescription,
            'inLanguage' => app()->getLocale(),
            'image' => $seoImage,
            'areaServed' => ['@type' => 'Country', 'name' => 'Honduras'],
            'publisher' => ['@id' => $seoCanonical . '#organization'],
        ],
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}
</script>
