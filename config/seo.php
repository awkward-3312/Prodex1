<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Public canonical base URL
    |--------------------------------------------------------------------------
    |
    | The absolute, https origin the marketing site is served from. Used to
    | build <link rel="canonical">, og:url, sitemap entries and JSON-LD @id
    | values regardless of the (possibly proxied / local) request host.
    | Never a trailing slash.
    |
    */

    'base_url' => rtrim(env('SEO_BASE_URL', 'https://prodexhub.cloud'), '/'),

    /*
    |--------------------------------------------------------------------------
    | Brand social-preview (Open Graph / Twitter) fallback image
    |--------------------------------------------------------------------------
    |
    | Path relative to public/. Used for og:image / twitter:image whenever the
    | CMS SEO section has no og_image configured. Must be 1200x630 for a
    | large-image card on Facebook, LinkedIn, WhatsApp and X.
    |
    */

    'og_image'        => env('SEO_OG_IMAGE', 'images/social/prodex-og.png'),
    'og_image_width'  => 1200,
    'og_image_height' => 630,
    'og_image_type'   => 'image/png',

    /*
    |--------------------------------------------------------------------------
    | Theme color (browser UI tint on mobile)
    |--------------------------------------------------------------------------
    */

    'theme_color' => '#0B1220',

    'default_description' => 'PRODEX es una plataforma ERP en la nube para negocios en Honduras: '
        .'ventas y punto de venta, inventario, compras, facturación y reportes en un solo lugar.',
];
