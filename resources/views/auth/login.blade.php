<!DOCTYPE html>
<html lang="es">
  <head>
    <meta charset="utf-8" />
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <link rel="stylesheet" href="/css/master.css">
    <link rel="stylesheet" href="{{ global_asset('css/auth.css') }}">
    <link rel="icon" href="{{ global_asset(upload_path('settings') . '/' . ($app_settings->favicon ?? 'favicon.ico')) }}">
    <title>{{ $app_settings->app_name ?? 'PRODEX' }}</title>
  </head>

  <body class="auth-login">
    <div class="auth-page">
      <section class="auth-hero">
        <div class="hero-content">
          <h1 class="hero-title">{{ $app_settings->login_hero_title ?? '¡Bienvenido de nuevo!' }}</h1>
          <p class="hero-subtitle">
            {{ $app_settings->login_hero_subtitle ?? 'Inicia sesión para acceder a tu cuenta y mantener tus operaciones sincronizadas.' }}
          </p>
        </div>
      </section>

      <section class="auth-panel">
        <div class="auth-panel-inner">
          <header>
            <h2 class="panel-title">{{ $app_settings->login_panel_title ?? 'Iniciar sesión' }}</h2>
            <p class="panel-subtitle">
              {{ $app_settings->login_panel_subtitle ?? 'Accede a tu panel y administra todo desde un solo lugar.' }}
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
