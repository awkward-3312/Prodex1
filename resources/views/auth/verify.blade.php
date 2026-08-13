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
        @if (session('resent'))
        <div class="auth-alert success" role="alert">
          {{ __('A fresh verification link has been sent to your email address.') }}
        </div>
        @endif
        <h1 class="auth-title">{{ __('Verify Your Email') }}</h1>
        <p class="auth-subtitle">{{ __('Before proceeding, please check your email for a verification link.') }}</p>
        <form method="POST" action="{{ route('verification.resend') }}">
          @csrf
          <button type="submit" class="auth-btn">{{ __('Resend verification email') }}</button>
        </form>
      </div>
    </div>
  </body>
</html>
