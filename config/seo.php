<?php

$brand = require __DIR__.'/brand.php';

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
    | CMS SEO section has no og_image configured. The supplied app icon is square; legacy custom previews keep 1200x630 for a
    | large-image card on Facebook, LinkedIn, WhatsApp and X.
    |
    */

    'og_image'        => env('SEO_OG_IMAGE', $brand['assets']['icon']),
    'og_image_width'  => env('SEO_OG_IMAGE') ? 1200 : 1024,
    'og_image_height' => env('SEO_OG_IMAGE') ? 630 : 1024,
    'og_image_type'   => 'image/png',

    /*
    |--------------------------------------------------------------------------
    | Theme color (browser UI tint on mobile)
    |--------------------------------------------------------------------------
    */

    'theme_color' => $brand['colors']['ink'],

    'default_description' => 'PRODEX es una plataforma ERP en la nube para negocios en Honduras: '
        .'ventas y punto de venta, inventario, compras, facturación y reportes en un solo lugar.',
];
