<!DOCTYPE html>
<html lang="es">
  <head>
    <meta charset="utf-8" />
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <link rel="stylesheet" href="/css/master.css">
    {{-- auth.css sets `font-family: "Inter"`; load the face so the tenant login matches the app. --}}
    <link rel="stylesheet" href="{{ global_asset('assets_super/css/inter.css') }}">
    <link rel="stylesheet" href="{{ global_asset('css/auth.css') }}">
    <link rel="icon" href="{{ global_asset(upload_path('settings') . '/' . ($app_settings->favicon ?? 'favicon.ico')) }}">
    <title>{{ $app_settings->app_name ?? 'PRODEX' }}</title>
  </head>

  <body class="auth-login">
    <div class="auth-page">
      <section class="auth-hero">
        <img class="hero-illustration" src="{{ global_asset('images/auth/login-illustration.png') }}" alt="PRODEX">
      </section>

      @php
          // login_panel_title/subtitle are tenant-customizable (SettingsController),
          // but every tenant's settings row was seeded with these literal English
          // strings at creation (2026_03_24_203803_create_settings_table). An
          // untouched seed value should follow the current locale (SetLocale
          // middleware) instead of staying stuck in English; an actually
          // customized value is left exactly as the tenant set it.
          $panelTitleSeedDefault = 'Sign In';
          $panelSubtitleSeedDefault = 'Access your dashboard and manage everything from one place.';
          $panelTitle = (! empty($app_settings->login_panel_title) && $app_settings->login_panel_title !== $panelTitleSeedDefault)
              ? $app_settings->login_panel_title
              : __('auth.login_panel_title');
          $panelSubtitle = (! empty($app_settings->login_panel_subtitle) && $app_settings->login_panel_subtitle !== $panelSubtitleSeedDefault)
              ? $app_settings->login_panel_subtitle
              : __('auth.login_panel_subtitle');
      @endphp
      <section class="auth-panel">
        <div class="auth-panel-inner">
          <header>
            <img class="tenant-login-logo" src="{{ tenancy()->tenant->loginLogoUrl() }}" alt="{{ $app_settings->app_name ?? 'PRODEX' }}">
            <h2 class="panel-title">{{ $panelTitle }}</h2>
            <p class="panel-subtitle">
              {{ $panelSubtitle }}
            </p>
          </header>

          @if (session('status'))
          <div class="auth-alert success">{{ session('status') }}</div>
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

          <form id="login_form" method="POST" action="{{ route('login') }}">
            @csrf
            <div class="field">
              <label for="email">Correo electrónico</label>
              <div class="input-shell">
                <span class="input-addon">@</span>
                <input id="email" type="email" name="email" value="{{ old('email') }}" placeholder="tu@empresa.com" required />
              </div>
            </div>

            <div class="field">
              <label for="password">Contraseña</label>
              <div class="input-shell">
                <span class="input-addon">••</span>
                <input id="password" type="password" name="password" placeholder="Ingresa tu contraseña" required />
                <button type="button" class="toggle-password" data-target="password">Mostrar</button>
              </div>
            </div>

            <div class="form-meta">
              <a class="auth-link" href="{{ route('password.request') }}">¿Olvidaste tu contraseña?</a>
            </div>

            <button type="submit" class="auth-btn" id="login_submit_btn">
              <span class="btn-text">Iniciar sesión</span>
              <span class="btn-loading"><span class="spinner"></span>Verificando</span>
            </button>
          </form>
        </div>
      </section>
    </div>

    <script src="{{ global_asset('assets_super/js/auth-login.js') }}"></script>
  </body>
</html>
