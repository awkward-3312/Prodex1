<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;

class PwaManifestController extends Controller
{
    protected array $surfaces = [
        'app' => [
            'short_name'       => 'PRODEX',
            'description'      => 'Gestión empresarial con punto de venta',
            'start_url'        => '/',
            'scope'            => '/',
            'display'          => 'standalone',
            'orientation'      => 'any',
            'background_color' => '#ffffff',
            'theme_color'      => '#2f3640',
        ],
        'store' => [
            'short_name'       => 'Tienda',
            'description'      => 'Tienda en línea',
            'start_url'        => '/online_store',
            'scope'            => '/online_store',
            'display'          => 'standalone',
            'orientation'      => 'any',
            'background_color' => '#ffffff',
            'theme_color'      => '#6c5ce7',
        ],
        'customer-display' => [
            'short_name'       => 'Pantalla',
            'description'      => 'Pantalla del punto de venta para el cliente',
            'start_url'        => '/customer-display',
            'scope'            => '/customer-display',
            'display'          => 'fullscreen',
            'orientation'      => 'landscape',
            'background_color' => '#0b0c10',
            'theme_color'      => '#0b0c10',
        ],
        'portal' => [
            'short_name'       => 'Portal',
            'description'      => 'Portal del cliente',
            'start_url'        => '/portal',
            'scope'            => '/portal',
            'display'          => 'standalone',
            'orientation'      => 'any',
            'background_color' => '#f1f5f9',
            'theme_color'      => '#2f3640',
        ],
    ];

    public function manifest(Request $request, string $type)
    {
        $surface = $this->surfaces[$type] ?? $this->surfaces['app'];

        $manifest = [
            'name'             => $this->appName($surface['short_name']),
            'short_name'       => $surface['short_name'],
            'description'      => $surface['description'],
            'start_url'        => $surface['start_url'],
            'scope'            => $surface['scope'],
            'display'          => $surface['display'],
            'orientation'      => $surface['orientation'],
            'background_color' => $surface['background_color'],
            'theme_color'      => $surface['theme_color'],
            'lang'             => 'es-HN',
            'dir'              => 'ltr',
            'icons'            => $this->icons(),
        ];

        return response()
            ->json($manifest, 200, [], JSON_UNESCAPED_SLASHES)
            ->header('Content-Type', 'application/manifest+json');
    }

    protected function appName(string $shortName): string
    {
        try {
            $setting = Setting::where('deleted_at', null)->first();
            $name = $setting->app_name ?? $setting->CompanyName ?? null;
            if ($name) {
                return $name;
            }
        } catch (\Throwable) {
        }

        return $shortName === 'PRODEX' ? 'PRODEX' : 'PRODEX ' . $shortName;
    }

    protected function icons(): array
    {
        $icon192 = pwa_icon_url(192);
        $icon512 = pwa_icon_url(512);

        return [
            ['src' => $icon192, 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => $icon512, 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => $icon192, 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'maskable'],
            ['src' => $icon512, 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
        ];
    }
}
