<!DOCTYPE html>
<html lang="es">
  <head>
    <link rel="stylesheet" href="{{ global_asset('css/prodex-brand.css') }}">
    <meta charset="utf-8" />
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="viewport" content="width=device-width,initial-scale=1.0" />
    <link rel="stylesheet" href="/css/master.css">
    <link rel="stylesheet" href="{{ global_asset('css/auth.css') }}">
    <link rel="icon" href="{{ \App\Support\PlatformBrand::tenantUrl($app_settings->favicon ?? null, 'icon') }}">
    <title>{{ $app_settings->app_name ?? 'PRODEX' }}</title>
  </head>
  <body class="text-left">
    <noscript>
      <strong>PRODEX necesita JavaScript para funcionar correctamente. Actívalo para continuar.</strong>
    </noscript>
    <div class="auth-wrapper">
      <div class="auth-card">
        <div class="auth-brand">
          <img src="{{ \App\Support\PlatformBrand::tenantUrl($app_settings->logo ?? null) }}" alt="PRODEX" />
        </div>
        @if ($errors->any())
        <div class="auth-alert error">
          <ul>
            @foreach ($errors->all() as $error)
            <li>{{ $error }}</li>
            @endforeach
          </ul>
        </div>
        @endif
        <h1 class="auth-title">Restablecer contraseña</h1>
        <p class="auth-subtitle">Ingresa tu nueva contraseña.</p>
        <form method="POST" action="{{ route('password.update') }}" novalidate>
          @csrf
          <input type="hidden" name="token" value="{{ $token }}" />
          <div class="form-group">
            <label for="email">Correo electrónico</label>
            <input id="email" type="email" name="email" class="auth-input" value="{{ old('email') }}" required autocomplete="email" />
          </div>
          <div class="form-group">
            <label for="password">Contraseña</label>
            <input id="password" type="password" name="password" class="auth-input" required autocomplete="new-password" />
          </div>
          <div class="form-group">
            <label for="password-confirm">Confirmar contraseña</label>
            <input id="password-confirm" type="password" name="password_confirmation" class="auth-input" required autocomplete="new-password" />
          </div>
          <button type="submit" class="auth-btn">Restablecer contraseña</button>
        </form>
      </div>
    </div>
  </body>
</html>