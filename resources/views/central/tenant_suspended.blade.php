<!DOCTYPE html>
@php
    $statusLabel = match (strtolower((string) $status)) {
        'suspended' => 'Suspendido',
        'cancelled', 'canceled' => 'Cancelado',
        'inactive' => 'Inactivo',
        'expired' => 'Vencido',
        default => ucfirst((string) $status),
    };
@endphp
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('central.Workspace') }} — {{ $statusLabel }}</title>
    <link rel="icon" href="{{ asset('images/super/settings/favicon.ico') }}">
    <link href="{{ asset('assets_super/css/plus-jakarta-sans.css') }}" rel="stylesheet">
    <link href="{{ asset('assets_super/css/status-pages.css') }}" rel="stylesheet">
</head>
<body class="page-suspended">
    <div class="suspended-container">
        <h1>{{ __('central.WorkspaceIsStatus', ['status' => strtolower($statusLabel)]) }}</h1>
        <p>{{ __('central.AccessDisabledContactSupport') }}</p>
    </div>
</body>
</html>
