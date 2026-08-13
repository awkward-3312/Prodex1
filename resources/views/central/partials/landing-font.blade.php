{{--
    Custom landing font override.
    Applies the super-admin selected font(s) across every landing layout.
    A font may be a curated Google family OR the admin's own uploaded font
    (registered via @font-face). Body font cascades to the whole page; heading
    font (optional) applies to titles, falling back to the body font when unset.
    Renders nothing when no font is chosen.
--}}
@php
    $gs = $generalSettings ?? \App\Models\Central\GeneralSetting::instance();
    $bodyFont = trim((string) ($gs->landing_font ?? ''));
    $headingFont = trim((string) ($gs->landing_heading_font ?? ''));

    // Uploaded custom font (optional).
    $hasCustom    = $gs->hasCustomLandingFont();
    $customName   = $hasCustom ? trim((string) $gs->landing_custom_font_name) : '';
    $customFormat = $hasCustom ? $gs->customLandingFontFormat() : null;

    // Only allow curated families or the configured custom font (defence-in-depth
    // alongside controller validation).
    $allowed = \App\Models\Central\GeneralSetting::landingFontKeys();
    $isAllowed = fn ($f) => $f !== '' && (in_array($f, $allowed, true) || ($hasCustom && $f === $customName));
    if (! $isAllowed($bodyFont)) { $bodyFont = ''; }
    if (! $isAllowed($headingFont)) { $headingFont = ''; }

    $effectiveHeading = $headingFont !== '' ? $headingFont : $bodyFont;

    // Selected families, split into Google families vs. the custom font.
    $selected = array_values(array_unique(array_filter([$bodyFont, $effectiveHeading], fn ($f) => $f !== '')));
    $googleFamilies = $hasCustom ? array_values(array_filter($selected, fn ($f) => $f !== $customName)) : $selected;
    $usesCustom = $hasCustom && in_array($customName, $selected, true);

    $query = implode('&', array_map(
        fn ($f) => 'family=' . str_replace(' ', '+', $f) . ':wght@400;500;600;700',
        $googleFamilies
    ));
@endphp
@if($bodyFont !== '' || $effectiveHeading !== '')
    @if($query !== '')
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?{{ $query }}&display=swap" rel="stylesheet">
    @endif
    <style id="custom-landing-font">
        @if($usesCustom)
        @font-face {
            font-family: '{{ $customName }}';
            src: url('{{ asset($gs->landing_custom_font_path) }}') format('{{ $customFormat }}');
            font-weight: 100 900;
            font-style: normal;
            font-display: swap;
        }
        @endif
        :root {
            @if($bodyFont !== '')
            --font-sans: '{{ $bodyFont }}', system-ui, -apple-system, sans-serif;
            --font-body: '{{ $bodyFont }}', system-ui, -apple-system, sans-serif;
            @endif
            @if($effectiveHeading !== '')
            --font-display: '{{ $effectiveHeading }}', Georgia, 'Times New Roman', serif;
            --font-heading: '{{ $effectiveHeading }}', Georgia, 'Times New Roman', serif;
            @endif
        }
        @if($bodyFont !== '')
        body,
        button, input, textarea, select, .btn,
        .navbar, .nav-link, p, a, span, li, label {
            font-family: '{{ $bodyFont }}', system-ui, -apple-system, sans-serif !important;
        }
        @endif
        @if($effectiveHeading !== '')
        h1, h2, h3, h4, h5, h6,
        .display-1, .display-2, .display-3, .display-4, .display-5, .display-6,
        [class*="hero-title"], [class*="__title"], [class*="-heading"], [class*="display"] {
            font-family: '{{ $effectiveHeading }}', Georgia, 'Times New Roman', serif !important;
        }
        @endif
    </style>
@endif
