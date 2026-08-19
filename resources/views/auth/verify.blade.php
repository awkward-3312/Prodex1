<!DOCTYPE html>
<html lang="es">
  <head>
    <meta charset="utf-8" />
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="viewport" content="width=device-width,initial-scale=1.0" />
    <link rel="stylesheet" href="/css/master.css">
    <link rel="stylesheet" href="{{ global_asset('css/auth.css') }}">
    <link rel="icon" href="{{ global_asset(upload_path('settings') . '/' . ($app_settings->favicon ?? 'favicon.ico')) }}">
    <title>{{ $app_settings->app_name ?? 'PRODEX' }}</title>
  </head>
  <body class="text-left">
    <noscript>
      <strong>
        PRODEX necesita JavaScript para funcionar correctamente. Actívalo para continuar.
      </strong>
    </noscript>
    <div class="auth-wrapper">
      <div class="auth-card">
        <div class="auth-brand">
          <img src="{{ global_asset(upload_path('settings') . '/' . ($app_settings->logo ?? 'logo-default.png')) }}" alt="PRODEX" />
        </div>
        @if (session('resent'))
        <div class="auth-alert success" role="alert">
          Se ha enviado un nuevo enlace de verificación a tu correo electrónico.
        </div>
        @endif
        <h1 class="auth-title">Verifica tu correo electrónico</h1>
        <p class="auth-subtitle">Antes de continuar, revisa tu correo electrónico y abre el enlace de verificación.</p>
        <form method="POST" action="{{ route('verification.resend') }}">
          @csrf
          <button type="submit" class="auth-btn">Reenviar correo de verificación</button>
        </form>
      </div>
    </div>
  </body>
</html>
