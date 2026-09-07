@php
    /*
     | Single source of truth for public-page <head> metadata:
     | description, canonical, Open Graph, Twitter, favicons, theme-color,
     | JSON-LD. Each page may override by defining these before the @include:
     |
     |   $seoTitle          string   (defaults to CMS meta_title / app name)
     |   $seoDescription    string
     |   $seoCanonicalUrl   string   absolute https URL for THIS page
     |   $seoImageUrl       string   absolute URL, 1200x630
     |   $seoRobots         string   e.g. 'noindex, nofollow'
     |   $seoType           string   og:type (default 'website')
     |
     | The <title> tag itself stays in each page (titles are computed per view).
     */
    $seoBase = rtrim(config('seo.base_url', 'https://prodexhub.cloud'), '/');

    $seoTitle = $seoTitle
        ?? ($seo->meta_title ?? null)
        ?? ($appName ?? config('app.name', 'PRODEX'));

    $seoDescription = $seoDescription
        ?? ($seo->meta_description ?? null)
        ?? config('seo.default_description');

    $seoPath = trim(request()->path(), '/');
    $seoCanonicalUrl = $seoCanonicalUrl
        ?? ($seoPath === '' ? $seoBase . '/' : $seoBase . '/' . $seoPath);

    // A CMS-configured OG image wins; only the brand fallback has known
    // dimensions, so width/height/type are emitted only for the fallback.
    $seoBrandImage = $seoBase . '/' . ltrim(config('seo.og_image'), '/');
    if (! empty($seoImageUrl)) {
        $seoImageIsBrand = ($seoImageUrl === $seoBrandImage);
    } elseif (! empty($seo->og_image)) {
        $seoImageUrl = asset($seo->og_image);
        $seoImageIsBrand = false;
    } else {
        $seoImageUrl = $seoBrandImage;
        $seoImageIsBrand = true;
    }

    $seoRobots = $seoRobots ?? 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1';
    $seoType   = $seoType ?? 'website';
    $seoLocale = str_replace('-', '_', app()->getLocale());
    $seoKeywords = $seo->meta_keywords ?? null;
    $seoThemeColor = config('seo.theme_color', '#0B1220');
@endphp

<meta name="description" content="{{ $seoDescription }}">
@if($seoKeywords)
    <meta name="keywords" content="{{ $seoKeywords }}">
@endif
<meta name="robots" content="{{ $seoRobots }}">
<link rel="canonical" href="{{ $seoCanonicalUrl }}">
<meta name="theme-color" content="{{ $seoThemeColor }}">
<meta name="geo.region" content="HN">
<meta name="geo.placename" content="Honduras">

<meta property="og:type" content="{{ $seoType }}">
<meta property="og:site_name" content="{{ config('app.name', 'PRODEX') }}">
<meta property="og:title" content="{{ $seoTitle }}">
<meta property="og:description" content="{{ $seoDescription }}">
<meta property="og:url" content="{{ $seoCanonicalUrl }}">
<meta property="og:locale" content="{{ $seoLocale }}">
<meta property="og:image" content="{{ $seoImageUrl }}">
<meta property="og:image:secure_url" content="{{ $seoImageUrl }}">
<meta property="og:image:alt" content="{{ $seoTitle }}">
@if($seoImageIsBrand)
    <meta property="og:image:type" content="{{ config('seo.og_image_type', 'image/png') }}">
    <meta property="og:image:width" content="{{ config('seo.og_image_width', 1200) }}">
    <meta property="og:image:height" content="{{ config('seo.og_image_height', 630) }}">
@endif

<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{{ $seoTitle }}">
<meta name="twitter:description" content="{{ $seoDescription }}">
<meta name="twitter:image" content="{{ $seoImageUrl }}">

@if(! empty($seo->favicon))
    <link rel="icon" href="{{ asset($seo->favicon) }}">
    <link rel="apple-touch-icon" href="{{ asset($seo->favicon) }}">
@else
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('images/social/favicon-32x32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('images/social/favicon-16x16.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('images/social/apple-touch-icon.png') }}">
@endif

<script type="application/ld+json">
{!! json_encode([
    '@context' => 'https://schema.org',
    '@graph' => [
        [
            '@type' => 'Organization',
            '@id' => $seoBase . '/#organization',
            'name' => config('app.name', 'PRODEX'),
            'alternateName' => 'PRODEX Honduras',
            'url' => $seoBase . '/',
            'logo' => $logoUrl ?? ($seoBase . '/' . ltrim(config('seo.og_image'), '/')),
            'areaServed' => ['@type' => 'Country', 'name' => 'Honduras'],
        ],
        [
            '@type' => 'WebSite',
            '@id' => $seoBase . '/#website',
            'name' => config('app.name', 'PRODEX'),
            'url' => $seoBase . '/',
            'inLanguage' => app()->getLocale(),
            'publisher' => ['@id' => $seoBase . '/#organization'],
        ],
        [
            '@type' => 'SoftwareApplication',
            '@id' => $seoBase . '/#software',
            'name' => config('app.name', 'PRODEX'),
            'url' => $seoBase . '/',
            'applicationCategory' => 'BusinessApplication',
            'operatingSystem' => 'Web',
            'description' => $seoDescription,
            'inLanguage' => app()->getLocale(),
            'image' => $seoImageUrl,
            'areaServed' => ['@type' => 'Country', 'name' => 'Honduras'],
            'publisher' => ['@id' => $seoBase . '/#organization'],
        ],
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}
</script>
