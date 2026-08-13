<?php

/**
 * Tenant web routes — Stocky app (dashboard, POS, store, portal, auth).
 * Loaded only when host is a tenant domain; runs with tenant DB.
 */

use App\Http\Controllers\QuickBooksController;
use App\Http\Controllers\StoreAuthController;
use App\Http\Controllers\StoreFrontController;
use App\Http\Controllers\Api\Store\AccountPagesController;
use App\Http\Controllers\Api\Store\CheckoutController;
use App\Http\Controllers\Api\Store\MessageController;
use App\Http\Controllers\Api\Store\MyOrdersApiController;
use App\Http\Controllers\Api\Store\NewsletterController;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::get('password/find/{token}', 'PasswordResetController@find');

// ------------------------------------------------------------------\\
// Per-tenant PWA manifests (public — fetched by the browser without auth).
// Served from /pwa/*.webmanifest so the web server doesn't shadow them with
// the legacy static public/manifest-*.webmanifest files.
Route::middleware('web')
    ->get('pwa/{type}.webmanifest', [\App\Http\Controllers\PwaManifestController::class, 'manifest'])
    ->where('type', 'app|store|customer-display|portal')
    ->name('pwa.manifest');

$installed = file_exists(base_path('storage/app/public/installed'));

// ------------------------------------------------------------------\\
// Online store (tenant)
if ($installed === true) {
    Route::middleware(['web', 'request.safety', 'store.enabled', 'tenant.feature:online_orders'])->group(function () {
        Route::prefix('online_store')->group(function () {
            Route::get('/lang/{locale}', function ($locale) {
                $supported = array_map('basename', glob(resource_path('lang/*')));
                $chosen = in_array($locale, $supported, true) ? $locale : 'en';
                session(['locale' => $chosen]);
                Cookie::queue('locale', $chosen, 60 * 24 * 365, '/');
                return back();
            })->name('lang.switch');

            Route::get('/', [StoreFrontController::class, 'index'])->name('store.index');
            Route::get('/shop', [StoreFrontController::class, 'shop'])->name('store.shop');
            Route::get('/search/suggestions', [StoreFrontController::class, 'searchSuggestions'])->name('store.search.suggestions');
            Route::get('/contact', [StoreFrontController::class, 'contact'])->name('store.contact');
            Route::post('/store/orders', [CheckoutController::class, 'store'])->name('store.orders.store');
            Route::post('/store/payment-intent', [CheckoutController::class, 'createPaymentIntent'])->name('store.payment.intent');
            Route::get('/collections/{slug}', [StoreFrontController::class, 'collection'])->name('store.collection.show');
            Route::post('/newsletter/subscribe', [NewsletterController::class, 'subscribe'])->name('newsletter.subscribe');
            Route::post('/contact/send', [MessageController::class, 'store'])->name('store.contact.send');

            Route::middleware(['web', 'auth:store'])->group(function () {
                Route::view('/checkout', 'store.checkout')->name('checkout');
                Route::view('/thank-you', 'store.thank-you')->name('store.thankyou');
                Route::get('/account', [AccountPagesController::class, 'account'])->name('account');
                Route::put('/account', [AccountPagesController::class, 'update'])->name('account.update');
                Route::get('/account/orders', [AccountPagesController::class, 'orders'])->name('account.orders');
                Route::get('/account/orders/{id}', function ($id) {
                    return view('store.order-show', ['id' => $id]);
                })->name('account.order.show');
                Route::get('/my/orders', [MyOrdersApiController::class, 'index'])->name('my_orders.index');
                Route::get('/my/orders/{id}', [MyOrdersApiController::class, 'show'])->name('my_orders.show');
            });

            Route::middleware('guest:store')->group(function () {
                Route::get('/login', [StoreAuthController::class, 'showLogin'])->name('store.login.show');
                Route::post('/login', [StoreAuthController::class, 'login'])->name('store.login');
                Route::get('/register', [StoreAuthController::class, 'showRegister'])->name('store.register.show');
                Route::post('/register', [StoreAuthController::class, 'register'])->name('store.register');
            });

            Route::post('/logout', [StoreAuthController::class, 'logout'])
                ->middleware('auth:store')->name('store.logout');
        });
    });
} else {
    Route::any('/online_store/{any?}', function () {
        abort(503, 'Store not available');
    })->where('any', '.*');
}

// ------------------------------------------------------------------\\
// Client Portal (tenant)
Route::get('/portal/{vue?}', function (\Illuminate\Http\Request $request, $vue = null) {
    // On tenant domains the tenancy filesystem bootstrapper changes disk roots,
    // so the "installed" marker must be checked via absolute path.
    $installed = file_exists(base_path('storage/app/public/installed'));
    if ($installed === false) {
        abort(503, 'Platform not ready');
    }
    $guestSegments = ['login', 'set-password'];
    $isGuestPath = in_array($vue, $guestSegments, true);
    if (! \Illuminate\Support\Facades\Auth::guard('portal')->check() && ! $isGuestPath) {
        return redirect('/portal/login');
    }
    return view('portal');
})->where('vue', '.*')->middleware(['web']);

// ------------------------------------------------------------------\\
// OAuth callbacks (tenant)
Route::group(['middleware' => ['web', 'auth:web', 'Is_Active']], function () {
    Route::get('/quickbooks/connect', [QuickBooksController::class, 'connect'])->name('quickbooks.connect');
    Route::get('/quickbooks/callback', [QuickBooksController::class, 'callback'])->name('quickbooks.callback');
    Route::get('/google-calendar/connect', [\App\Http\Controllers\GoogleCalendarConnectController::class, 'connect'])->name('google_calendar.connect');
    Route::get('/google-calendar/callback', [\App\Http\Controllers\GoogleCalendarConnectController::class, 'callback'])->name('google_calendar.callback');
    Route::get('/google-calendar/disconnect', [\App\Http\Controllers\GoogleCalendarConnectController::class, 'disconnect'])->name('google_calendar.disconnect');
});

// ------------------------------------------------------------------\\
// Billing / subscription (tenant — no active-subscription middleware to avoid redirect loop)
Route::middleware(['web', 'auth:web'])->prefix('billing')->name('billing.')->group(function () {
    Route::get('/plans', [\App\Http\Controllers\Tenant\BillingController::class, 'plans'])->name('plans');
    Route::get('/checkout/{plan}', [\App\Http\Controllers\Tenant\BillingController::class, 'checkout'])->name('checkout');
    Route::post('/checkout/{plan}', [\App\Http\Controllers\Tenant\BillingController::class, 'processPayment'])->name('process');
    Route::get('/callback/{gateway}', [\App\Http\Controllers\Tenant\BillingController::class, 'callback'])->name('callback');
    Route::get('/success', [\App\Http\Controllers\Tenant\BillingController::class, 'success'])->name('success');
    Route::get('/offline-submitted', [\App\Http\Controllers\Tenant\BillingController::class, 'offlineSubmitted'])->name('offline-submitted');
    Route::get('/failed', [\App\Http\Controllers\Tenant\BillingController::class, 'failed'])->name('failed');
    Route::get('/payment/{payment}/status', [\App\Http\Controllers\Tenant\BillingController::class, 'paymentStatus'])->name('payment-status');
    Route::post('/payment/{payment}/confirm', [\App\Http\Controllers\Tenant\BillingController::class, 'confirmPayment'])->middleware('throttle:10,1')->name('payment-confirm');
    Route::get('/history', [\App\Http\Controllers\Tenant\BillingController::class, 'history'])->name('history');
    Route::post('/retry/{payment}', [\App\Http\Controllers\Tenant\BillingController::class, 'retry'])->name('retry');
    Route::post('/payment/{payment}/cancel', [\App\Http\Controllers\Tenant\BillingController::class, 'cancelPayment'])->name('cancel-payment');
});

// ------------------------------------------------------------------\\
// Tenant root — open the POS app (dashboard when authenticated, login
// for guests). The online store stays reachable at /online_store.
// Must be registered before the SPA catch-all.
Route::middleware(['web'])->get('/', function () {
    if (! file_exists(base_path('storage/app/public/installed'))) {
        abort(503, 'Platform not ready');
    }

    return Auth::guard('web')->check()
        ? redirect('/app/dashboard')
        : redirect()->guest(route('login'));
});

// ------------------------------------------------------------------\\
// SPA catch-all (tenant)
Route::group(['middleware' => ['web', 'auth:web', 'Is_Active', 'request.safety', 'tenant.subscribed', 'tenant.activity']], function () {
    Route::get('/{vue?}', function () {
        if (! file_exists(base_path('storage/app/public/installed'))) {
            abort(503, 'Platform not ready');
        }
        return view('layouts.master');
    })->where('vue', '^(?!api|password|online_store|customer-display|quickbooks|portal|login|logout|register|billing).*$');
});

// Auth (tenant)
Route::get('login', 'Auth\LoginController@showLoginForm')->name('login');
Route::post('login', 'Auth\LoginController@login');
Route::post('logout', 'Auth\LoginController@logout')->name('logout');
Route::get('password/reset', 'Auth\ForgotPasswordController@showLinkRequestForm')->name('password.request');
Route::post('password/email', 'Auth\ForgotPasswordController@sendResetLinkEmail')->name('password.email');
Route::get('password/reset/{token}', 'Auth\ResetPasswordController@showResetForm')->name('password.reset');
Route::post('password/reset', 'Auth\ResetPasswordController@reset')->name('password.update');
Route::get('email/verify', 'Auth\VerificationController@show')->name('verification.notice');
Route::get('email/verify/{id}/{hash}', 'Auth\VerificationController@verify')->name('verification.verify');
Route::post('email/resend', 'Auth\VerificationController@resend')->name('verification.resend');

// Customer display (tenant)
Route::get('/customer-display', function (HttpRequest $request) {
    $token = $request->query('token');
    $expected = Cache::store(config('cache.default'))->get('customer_display_token');
    if (! $token || $token !== $expected) {
        abort(403, 'Unauthorized display access');
    }
    return view('customer_display');
})->middleware(['web']);
