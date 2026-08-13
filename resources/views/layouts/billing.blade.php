<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Billing') — {{ config('app.name') }}</title>
    <link href="{{ global_asset('assets_super/css/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ global_asset('assets_super/css/bootstrap-icons.min.css') }}" rel="stylesheet">
    <link href="{{ global_asset('assets_super/css/billing.css') }}" rel="stylesheet">
    @stack('styles')
</head>
<body>
    <header class="billing-topbar">
        <a href="/billing/plans" class="billing-brand">
            <i class="bi bi-hexagon-fill"></i>
            {{ config('app.name') }}
        </a>
        <nav class="billing-nav">
            <a href="{{ route('billing.plans') }}" class="{{ request()->routeIs('billing.plans') || request()->routeIs('billing.checkout') ? 'active' : '' }}">Plans</a>
            <a href="{{ route('billing.history') }}" class="{{ request()->routeIs('billing.history') ? 'active' : '' }}">Billing History</a>
        </nav>
        <div class="billing-user">
            @php $authUser = auth()->user(); @endphp
            <span class="d-none d-md-inline text-muted">{{ $authUser->name ?? '' }}</span>
            <div class="avatar">{{ strtoupper(mb_substr($authUser->name ?? 'U', 0, 1)) }}</div>
            <a href="/" class="btn-billing btn-billing-outline btn-billing-sm">
                <i class="bi bi-arrow-left"></i> Back to App
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
        <i class="bi bi-shield-lock-fill"></i> Payments are secure and encrypted. &copy; {{ date('Y') }} {{ config('app.name') }}
    </footer>

    <script src="{{ global_asset('assets_super/js/bootstrap.bundle.min.js') }}"></script>
    @stack('scripts')
</body>
</html>
