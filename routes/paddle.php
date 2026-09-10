<?php

use App\Http\Controllers\Central\PaddleWebhookController;
use App\Http\Middleware\SerializePaddleWebhook;
use Illuminate\Support\Facades\Route;

// Paddle server-to-server webhook. No session/auth/CSRF middleware: every
// request is authenticated against the exact raw body using Paddle-Signature.
// Duplicate deliveries for the same event are serialized before fulfillment.
Route::post('/webhook/paddle', [PaddleWebhookController::class, 'handle'])
    ->middleware([SerializePaddleWebhook::class, 'throttle:120,1'])
    ->name('webhook.paddle');
