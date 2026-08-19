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
      <strong>PRODEX necesita JavaScript para funcionar correctamente. Actívalo para continuar.</strong>
    </noscript>
    <div class="auth-wrapper">
      <div class="auth-card">
        <div class="auth-brand">
          <img src="{{ global_asset(upload_path('settings') . '/' . ($app_settings->logo ?? 'logo-default.png')) }}" alt="PRODEX" />
        </div>
        @if (session('status'))
        <div class="auth-alert success" role="alert">
          {{ session('status') }}
        </div>
        @endif
        @if ($errors->any())
        <div class="auth-alert error">
          <ul>
            @foreach ($errors->all() as $error)
            <li>{{ $error }}</li>
            @endforeach
          </ul>
        </div>
        @endif
        <h1 class="auth-title">¿Olvidaste tu contraseña?</h1>
        <p class="auth-subtitle">Ingresa tu correo electrónico para recibir un enlace de restablecimiento.</p>
        <form method="POST" action="{{ route('password.email') }}" novalidate>
          @csrf
          <div class="form-group">
            <label for="email">Correo electrónico</label>
            <input id="email" type="email" name="email" class="auth-input" value="{{ old('email') }}" required autocomplete="email" autofocus />
          </div>
          <button type="submit" class="auth-btn">Enviar enlace para restablecer la contraseña</button>
        </form>
        <div class="auth-actions auth-actions--center">
          <a class="auth-link" href="{{ route('login') }}">Volver al inicio de sesión</a>
        </div>
      </div>
    </div>
  </body>
</html>