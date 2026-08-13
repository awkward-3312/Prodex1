<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="viewport" content="width=device-width,initial-scale=1.0" />
    <link rel="stylesheet" href="/css/master.css">
    <link rel="stylesheet" href="{{ global_asset('css/auth.css') }}">
    <link rel="icon" href="{{ global_asset(upload_path('settings') . '/' . ($app_settings->favicon ?? 'favicon.ico')) }}">
    <title>{{ $app_settings->app_name ?? 'Stocky | Ultimate Inventory With POS' }}</title>
  </head>
  <body class="text-left">
    <noscript>
      <strong>
        We're sorry but Stocky doesn't work properly without JavaScript
        enabled. Please enable it to continue.</strong>
    </noscript>
    <div class="auth-wrapper">
      <div class="auth-card">
        <div class="auth-brand">
          <img src="{{ global_asset(upload_path('settings') . '/' . ($app_settings->logo ?? 'logo-default.png')) }}" alt="logo" />
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
        <h1 class="auth-title">{{ __('Reset Password') }}</h1>
        <p class="auth-subtitle">{{ __('Enter your new password below.') }}</p>
        <form method="POST" action="{{ route('password.update') }}" novalidate>
          @csrf
          <input type="hidden" name="token" value="{{ $token }}" />
          <div class="form-group">
            <label for="email">{{ __('E-Mail Address') }}</label>
            <input id="email" type="email" name="email" class="auth-input" value="{{ old('email') }}" required autocomplete="email" />
          </div>
          <div class="form-group">
            <label for="password">{{ __('Password') }}</label>
            <input id="password" type="password" name="password" class="auth-input" required autocomplete="new-password" />
          </div>
          <div class="form-group">
            <label for="password-confirm">{{ __('Confirm Password') }}</label>
            <input id="password-confirm" type="password" name="password_confirmation" class="auth-input" required autocomplete="new-password" />
          </div>
          <button type="submit" class="auth-btn">{{ __('Reset Password') }}</button>
        </form>
      </div>
    </div>
  </body>
</html>