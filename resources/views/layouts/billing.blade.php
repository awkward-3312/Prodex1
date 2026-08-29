<!DOCTYPE html>
<html lang="es" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Facturación') — {{ config('app.name', 'PRODEX') }}</title>
    <link href="{{ global_asset('assets_super/css/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ global_asset('assets_super/css/bootstrap-icons.min.css') }}" rel="stylesheet">
    {{-- billing.css already sets `font-family: 'Inter'`; load the face so it renders (matches the app). --}}
    <link href="{{ global_asset('assets_super/css/inter.css') }}" rel="stylesheet">
    <link href="{{ global_asset('assets_super/css/billing.css') }}" rel="stylesheet">
    @stack('styles')
</head>
<body>
    <header class="billing-topbar">
        <a href="/billing/plans" class="billing-brand">
            <i class="bi bi-hexagon-fill"></i>
            {{ config('app.name', 'PRODEX') }}
        </a>
        <nav class="billing-nav">
            <a href="{{ route('billing.plans') }}" class="{{ request()->routeIs('billing.plans') || request()->routeIs('billing.checkout') ? 'active' : '' }}">Planes</a>
            <a href="{{ route('billing.history') }}" class="{{ request()->routeIs('billing.history') ? 'active' : '' }}">Historial de facturación</a>
        </nav>
        <div class="billing-user">
            @php $authUser = auth()->user(); @endphp
            <span class="d-none d-md-inline text-muted">{{ $authUser->name ?? '' }}</span>
            <div class="avatar">{{ strtoupper(mb_substr($authUser->name ?? 'U', 0, 1)) }}</div>
            <a href="/" class="btn-billing btn-billing-outline btn-billing-sm">
                <i class="bi bi-arrow-left"></i> Volver a la aplicación
            </a>
        </div>
    </header>

    <div class="billing-container">
        @if(session('success'))
            <div class="alert-billing alert-billing-success"><i class="bi bi-check-circle-fill"></i> {{ session('success') }}</div>
        @endif
        @if(session('warning'))
            <div class="alert-billing alert-billing-warning"><i class="bi bi-exclamation-triangle-fill"></i> {{ session('warning') }}</div>
        @endif
        @if($errors->any())
            <div class="alert-billing alert-billing-danger"><i class="bi bi-x-circle-fill"></i> {{ $errors->first() }}</div>
        @endif
        @yield('content')
    </div>

    <footer class="billing-footer">
        <i class="bi bi-shield-lock-fill"></i> Los pagos son seguros y están cifrados. &copy; {{ date('Y') }} {{ config('app.name', 'PRODEX') }}
    </footer>

    <script src="{{ global_asset('assets_super/js/bootstrap.bundle.min.js') }}"></script>
    @stack('scripts')
</body>
</html>
