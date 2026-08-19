<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="viewport" content="width=device-width,initial-scale=1.0" />
    <link rel="stylesheet" href="/css/master.css">
    <link rel="icon" href="{{ global_asset(upload_path('settings') . '/' . ($app_settings->favicon ?? 'favicon.ico')) }}">
    <title>{{ $app_settings->app_name ?? 'Stocky | Ultimate Inventory With POS' }}</title>

    {{-- PWA --}}
    <link rel="manifest" href="/pwa/app.webmanifest">
    <meta name="theme-color" content="#2f3640">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="{{ $app_settings->app_name ?? 'Stocky' }}">
    <link rel="apple-touch-icon" href="{{ pwa_icon_url(192) }}">

  </head>

  <body class="text-left">
    <noscript>
      <strong>
        We're sorry but Stocky doesn't work properly without JavaScript
        enabled. Please enable it to continue.</strong
      >
    </noscript>

    <!-- built files will be auto injected -->
    <div class="loading_wrap" id="loading_wrap">

      <div class="loading"></div>
    </div>
    <div id="app">
      <script src="/assets_setup/js/qrcode.js"></script>

    </div>

    @php
        $__planSummary = app(\App\Services\TenantLimitsService::class)->getPlanSummary();

        // Manual PRODEX is official help/documentation, not a paid module.
        // Keep the existing sidebar entry available independently of the
        // tenant's commercial plan while leaving actual plan entitlements
        // unchanged in the central database.
        if (isset($__planSummary['features']['knowledge_base'])) {
            $__planSummary['features']['knowledge_base']['enabled'] = true;
        }
    @endphp
    <script>
        window.__planSummary = @json($__planSummary);
        window.__uploadPath = '{{ upload_path() }}';
        window.__appName = @json($app_settings->app_name ?? 'Stocky');
        window.__pageTitleSuffix = @json($app_settings->page_title_suffix ?? 'Ultimate Inventory With POS');
    </script>


    <script src="/js/main.min.js?v=1.3&v={{ time() }}"></script>

    @include('partials.plan-upgrade-modal')
    @include('partials.subscription-reminder-banner')
  </body>
</html>
